<?php
/**
* Joyst
* A schema based CodeIgniter extension providing automated model functions
*
* @package Joyst
* @url https://github.com/hash-bang/Joyst
* @author Matt Carter <m@ttcarter.com>
*/

// Sanity checks {{{
if (!function_exists('load_class')) {
	trigger_error("JOYST - Can't find CodeIgniter load_model() function. Try you trying to load Joyst before loading CodeIgniter?");
	die();
}

if (!class_exists('CI_Controller')) // CI not ready yet
	return;

if (class_exists('Joyst_Controller')) // Already loaded?
	return;
// }}}

class Joyst_Controller extends CI_Controller {
	/**
	* The default routing rules used in JoystModel() if no specific rules are specified
	* @var array
	* @see JoystModel()
	*/
	var $defaultRoutes = array(
		'index' => 'getall(#)',
		'get' => 'get(*)',
		'create' => 'create(#)',
		'delete' => 'delete(#)',
		'meta' => 'getschema()',
		':num' => 'get(1)',
	);


	/**
	* Connect to a Joyst_Model and automatically route requests into various model functions
	*
	* Routing is specified in the form 'path' => 'function(parameters...)'
	*
	* Parameters can be one or more of the following seperated by commas:
	* 	* `#` - All remaining parameters as a hash
	*       * `1..9` - A specific numbered parameter from the input
	*       * `*` - All left over parameters in the parameter order
	*
	* @param string $model The name of the CI model (extending Joyst_Model) to use
	* @param array $routes The routing array to use. If unspecified $defaultRoutes will be substituted
	*/
	function JoystModel($model, $routes = null) {
		if (!$routes)
			$routes = $this->defaultRoutes;

		$segments = $this->uri->segments;
		array_shift($segments); // Shift first segment - assuming its the controller name
		if (!$segments) // No arguments after controller name
			return;
		$segment = array_shift($segments); // Retrieve argument if any

		// Determine the route to use
		$matchingRoute = null;
		foreach ($routes as $route => $dest) {
			switch ($route) {
				case ':num':
					if (is_numeric($segment)) {
						$matchingRoute = $route;
						array_unshift($segments, $segment); // Put the segment back
						break 2;
					}
					break;
				default:
					if ($segment == $route) {
						$matchingRoute = $route;
						break 2;
					}
			}
		}

		if (!$matchingRoute) // Didn't find anything matching in routes
			return;
		$rawfunc = $routes[$matchingRoute];
		$rawfunc = 'debug(*)';

		// Extract any additional parameters
		$params = $_GET;

		if (!preg_match('/^(.+?)\((.*)\)$/', $rawfunc, $matches))
			die('Joyst_Controller: Invalid routing function format. Should be in the format func(), func(a), func(*), func(1) or similar. Given: ' . $func);
		$func = $matches[1];

		// Determine the arguments to be passed to the routing function {{{
		$args = array();
		foreach (explode(',', $matches[2]) as $arg)
			switch ($arg) {
				case '#':
					$args[] = $params;
					break;
				case '1':
				case '2':
				case '3':
				case '4':
				case '5':
				case '6':
				case '7':
				case '8':
				case '9':
					if (!$segments)
						$this->JSONError('Invalid URL format');
					$args = $segments[$arg-1];
					break;
				case '*':
					$args = $segments;
					$segments = array();
					break;
			}
		// }}}

		// Check the model is loaded and call the function
		$this->load->model($model);
		call_user_func_array(array($this->$model, $func), $args);
	}
}
