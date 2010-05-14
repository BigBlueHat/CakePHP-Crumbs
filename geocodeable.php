<?php
/**
 * Geocodable Model Behavior for CakePHP
 *
 * Adds Geocode data to specified fields beforeSave
 *
 * @package behaviors
 * @author Benjamin Young based on Geocode Model work by Darren Moore, zeeneo@gmail.com and WhoDidIt (for base behavior layout) by Daniel Vecchiato
 * @version 0.1
 * @date 2009-09-04
 * @copyright BigBlueHat based on work by Darren Moore
 * @licence MIT
 **/

App::import('Core', 'HttpSocket');

class GeocodeableBehavior extends ModelBehavior
{
	/**
	* Default setup, fields, and providers settings which can be overriden by the individual model using this behavior
	*
	* @var array
	* @access protected
	*/
	protected $_defaults = array(
		'setup'=>array('provider'=>'google', 'country_code'=>'US'),
		'fields'=>array(
			'lat'=>'geo_lat',
			'lng'=>'geo_long',
			'street_address'=>'address',
			'city'=>'city',
			'region'=>'state',
			'postal_code'=>'zip',
			'country'=>'country'),
		'providers' => array(
			// 15,000 count rate limit per 24-hours per IP address
			'google'	=> array(
				'enabled'   => true,
				'api'	   => 'your-api-key-here',
				'url'	   => 'http://maps.google.com/maps/geo?q=:q&output=xml&key=:api',
				'fields'	=> array(
					'lng'	   => '/<coordinates>(.*?),/',
					'lat'	   => '/,(.*?),[^,\s]+<\/coordinates>/',
					'address1'  => '/<address>(.*?)<\/address>/',
					'postcode'  =>  '/<PostalCodeNumber>(.*?)<\/PostalCodeNumber>/',
					'country'   =>  '/<CountryNameCode>(.*?)<\/CountryNameCode>/'
				)
			),
			// Multimap by Bing/Microsoft is for non-commercial use only
			'multimap'  => array(
				'enabled'   => true,
				'api'	   => 'your-api-key-here',
				'url'	   => 'http://developer.multimap.com/API/geocode/1.2/:api?qs=:q&countryCode=:countryCode',
				'fields'	=> array(
					'lat'	   => '/<Lat>(.*?)<\/Lat>/',
					'lng'	   => '/<Lon>(.*?)<\/Lon>/',
					'postcode'  =>  '/<PostalCode>(.*?)<\/PostalCode>/',
					'country'   =>  '/<CountryCode>(.*?)<\/CountryCode>/'
				)
			),
			// 5,000 count rate limit, and we're not allowed to store the output, so probably not worth the trouble
			// http://info.yahoo.com/legal/us/yahoo/maps/mapsapi/mapsapi-2141.html
			'yahoo'  => array(
				'enabled'   => true,
				'api'	   => 'your-api-key-here',
				'url'	   => 'http://api.local.yahoo.com/MapsService/V1/geocode?appid=:api&location=:q',
				'fields'	=> array(
					'lat'	   => '/<Latitude>(.*?)<\/Latitude>/',
					'lng'	   => '/<Longitude>(.*?)<\/Longitude>/',
					'town'	  => '/<City>(.*?), /',
					'postcode'  =>  '/<Zip>(.*?)<\/Zip>/',
					'country'   =>  '/<Country>(.*?)<\/Country>/'
				)
			)
			// TODO: implement more of these: http://groups.google.com/group/Google-Maps-API/web/resources-non-google-geocoders
		)
	);

	/**
	 * Initiate WhoMadeIt Behavior
	 *
	 * @param object $model
	 * @param array $config  behavior settings you would like to override
	 * @return void
	 * @access public
	 */
	function setup(&$model, $settings = array())
	{
		$this->connection = new HttpSocket();

		//assigne default settings
		$this->settings[$model->alias] = $this->_defaults;

		//merge custom config with default settings
		$this->settings[$model->alias] = array_merge($this->settings[$model->alias], (array)$settings);

		$this->settings[$model->alias]['has_lat'] = $model->hasField($this->settings[$model->alias]['fields']['lat']);
		$this->settings[$model->alias]['has_lng'] = $model->hasField($this->settings[$model->alias]['fields']['lng']);
	}

	/**
	 * Before save callback
	 *
	 * @param object $model Model using this behavior
	 * @return boolean True if the operation should continue, false if it should abort
	 * @access public
	 */
	function beforeSave(&$model)
	{
		if ($this->settings[$model->alias]['has_lat']
			&& $this->settings[$model->alias]['has_lng']) {
			$geocode_data = $this->find($this->__makeAddressString($model), array('model'=>$model));
			$model->data[$model->alias][$this->settings[$model->alias]['fields']['lat']] = $geocode_data['lat'];
			$model->data[$model->alias][$this->settings[$model->alias]['fields']['lng']] = $geocode_data['lng'];
		}
		return true;
	}

	/**
	 * Find location
	 *
	 * @param string $q Query
	 * @param array $options Options when getting location, as followed:
	 *						  - cache: Force caching on or off
	 *						  - provider: Who to use for lookup, otherwise use $defaultProvider
	 *						  - country_code: Country code for searching, e.g. GB
	 * @access public
	 * @return array
	 */
	public function find($q,$options = array())
	{
		// Check query exists
		if (empty($q)) { $this->errors[] = 'Missing Query'; return false; }

		extract($this->settings[$options['model']->alias]['setup']);

		// Exception if UK postcode then always use multimap
		// Google postcode is rubbish!
		if($country_code == 'GB'
			&& !isset($options['provider'])
			&& preg_match('/^([Gg][Ii][Rr] 0[Aa]{2})|((([A-Za-z][0-9]{1,2})|(([A-Za-z][A-Ha-hJ-Yj-y][0-9]{1,2})|(([A-Za-z][0-9][A-Za-z])|([A-Za-z][A-Ha-hJ-Yj-y][0-9]?[A-Za-z])))) {0,1}[0-9][A-Za-z]{2})$/i',$q)) {
			$options['provider'] = 'multimap';
		}

		// Default settings
		$options = array_merge(
			$options,
			array(
				'provider'	=> $provider,
				'country_code' => $country_code,
				'cache'	   => true,
				'model' => $options['model']
			)
		);

		// Get coordinates from provider
		$data = $this->__geocoords($q,$options);

		// Save data and return
		if (!empty($data)) {
			$data = array_merge(
				array(
					'id'		=> 0,
					'key'	   => $q,
					'provider'  => $options['provider']
				),
				$data
			);
		}

		return $data;
	}

	private function __makeAddressString(&$model)
	{
		$address[] = @$model->data[$model->alias][$this->settings[$model->alias]['fields']['street_address']];
		$address[] = @$model->data[$model->alias][$this->settings[$model->alias]['fields']['city']];
		$address[] = @$model->data[$model->alias][$this->settings[$model->alias]['fields']['region']];
		$address[] = @$model->data[$model->alias][$this->settings[$model->alias]['fields']['postal_code']];
		$address[] = @$model->data[$model->alias][$this->settings[$model->alias]['fields']['country']];
		foreach ($address as $k => $v) {
			if (empty($v)) unset($address[$k]);
		}
		return implode(', ', $address);
	}

	/**
	 * Get Lng/Lat from provider
	 *
	 * @param string $q Query
	 * @param array $options Options
	 * @see find
	 * @access private
	 * @return array
	 */
	private function __geocoords($q,$options = array())
	{
		$data = array();

		//Extract variables to use
		extract($options);
		extract($this->settings[$model->alias]['providers'][$provider]);

		//Add country code to query
		$q .= ', '.$country_code;

		//Build url
		$url = String::insert($url,compact('api','q','country_code'));

		//Get data and parse
		if ($result = $this->connection->get($url)) {
			foreach ($fields as $field => $regex) {
				if (preg_match($regex,$result,$match)) {
					if (!empty($match[1]))
						$data[$field] = $match[1];
				}
			}
		}

		return $data;
	}
}
