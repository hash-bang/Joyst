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
		'' => 'getall(#)',
		'index' => 'getall(#)',
		'get' => 'get(*)',
		'create' => 'create(#)',
		'delete' => 'delete(#)',
		'meta' => 'getschema()',
		':num' => 'get(1)',
	);

	/**
	* Default options passed to json_encode() to output JSON
	* @var int
	*/
	var $JSONOptions = 0;


	/**
	* Convenience wrapper to return if the client is asking for some specific type of output
	* There is a special _GET variable called 'json' which if set forces JSON mode. If set to 'nice' this can also pretty print on JSON() calls
	* @param $type A type which corresponds to a known data type e.g. 'html', 'json'
	* @return bool True if the client is asking for that given data type
	*/
	function RequesterWants($type) {
		switch ($type) {
			case 'html':
				return !$this->Want('json');
			case 'json':
				if (isset($_GET['json'])) {
					if ($_GET['json'] == 'nice' && version_compare(PHP_VERSION, '5.4.0') >= 0)
						$this->JSONOptions = JSON_PRETTY_PRINT;
					return TRUE;
				}
				if (
					isset($_SERVER['HTTP_ACCEPT']) &&
					preg_match('!application/json!', $_SERVER['HTTP_ACCEPT'])
				)
					return TRUE;
				return FALSE;
			case 'put-json': // Being passed IN a JSON blob (also converts incomming JSON into $_POST variables
				if (!$this->Want('json')) // Not wanting JSON
					return FALSE;
				$in = file_get_contents('php://input');
				if (!$in) // Nothing in raw POST
					return FALSE;
				$json = json_decode($in, true);
				if ($json === null) // Not JSON
					return FALSE;
				$_POST = $json;
				return TRUE;
			default:
				trigger_error("Unknown want type: '$type'");
		}
	}

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
		if (!$this->RequesterWants('json')) // Not wanting JSON - fall though to regular controller which should handle base HTML requests
			return;

		if (!$routes)
			$routes = $this->defaultRoutes;

		$segments = $this->uri->segments;
		array_shift($segments); // Shift first segment - assuming its the controller name
		if (!$segments) { // No arguments after controller name
			if (isset($routes[''])) { // blank route exists
				$matchingRoute = '';
			} else
				return; // No support for blank route is present and we have nothing to work with
		} else {
			$segment = array_shift($segments); // Retrieve argument if any

			// Determine the route to use
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
		}

		if (!isset($matchingRoute)) // Didn't find anything matching in routes
			return;
		$rawfunc = $routes[$matchingRoute];

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
					$args[] = $segments[$arg-1];
					break;
				case '*':
					$args = $segments;
					$segments = array();
					break;
			}
		// }}}

		// Call function {{{
		// Check the model is loaded and call the function
		$this->load->model($model);
		//echo "Call \$this->$model->$func(" . json_encode($args) . ")<br/>";
		$return = call_user_func_array(array($this->$model, $func), $args);
		// }}}

		// Return output {{{
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT" ); 
		header("Last-Modified: " . gmdate( "D, d M Y H:i:s" ) . "GMT" ); 
		header("Cache-Control: no-cache, must-revalidate" ); 
		header("Pragma: no-cache" );
		header('Content-type: application/json');

		echo json_encode($return, $this->JSONOptions);
		exit;
		// }}}
	}
}
