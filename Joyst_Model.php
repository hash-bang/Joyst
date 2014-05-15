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
if (class_exists('Joyst_Model')) // Already loaded?
	return;

if (!class_exists('CI_Model')) {
	if (!function_exists('load_class')) {
		trigger_error("JOYST - Can't find CodeIgniter load_model() function. Try you trying to load Joyst before loading CodeIgniter?");
		die();
	} else {
		load_class('Model', 'core');
		if (!class_exists('CI_Model'))
			trigger_error("JOYST - Can't find CI_Model class even after requesting that CodeIgniter loads it via load_class('Model','core'). Something has gone seriously wrong.") && die();
	}
}
// }}}

/**
* Schema (stored in $this->schema):
* form of the array os 'field_name' => array_of_attributes.
*
* Valid attribtues:
* @param string type The type of field, this should correspond with the DB type or: pk - primary key (implies readonly=1), fk - foreign key
* @param int length The length of the field
* @param array|hash options Used if type = 'enum' this contains either an array of options or a hash of possible keys and their human translation
* @param bool readonly Field should not allow saves
* @param bool hide Alias for !allowget setting this will hide the output from all get() and getall() calls
* @param bool|function allowsave Allow this field to be updated in a save() operation (default: true)
* @param bool|function allowcreate Allow this field to be set in a create() operation (default: true)
* @param bool|function allowget Allow this field to be returned in a get() or getall() operation (default: true)
* @param bool|function allowquery Allow this field to be used to filter items (default: true)
*
*
* Triggers:
*	create(&$data) - Used before the insert call in a DB
*	created($id, $data) - Called after the successful insert of a record
*	delete(&$id) - Delete a specific record by its ID if the ID is unset or set to null the operation is aborted (this can be used to remap to a save() call instead)
*	deleted($id, $data) - Called after the successful delete of a record
*	deleteall(&$where, &$orderby) - Similar to getall this trigger is called prior to a DeleteAll() function
*	getall(&$where, &$orderby, &$limit, &$offset) - Used to add additional default parameters to the GetAll() function before it runs
*	getschema(&$schema) - Asked to return a schema map to the caller
*	row(&$row) - Mangling function to rewrite a record (used in Get() and GetAll() functions per row)
*	rows(&$rows) - Fired after the getall() function has completed. Typically used to resort results or further filter records
*	save(&$id, &$data) - Used before the update call in a DB
*	saved($id, $data) - Called after the saving of a record
*	setschema(&$schema) - Schema is loaded
*/
class Joyst_Model extends CI_Model {
	/**
	* Name of this model for tracking purposes
	* This can also be loaded by setting '_model' in $schema
	* @var string
	*/
	var $model = '';

	/**
	* Name of the DB table to manipulate
	* This can also be loaded by setting '_table' in $schema
	* @var string
	*/
	var $table = '';

	/**
	* The schema object
	*
	* @var array
	*/
	var $schema = array();

	/**
	* Storage for all hooks
	* Use the On(), Off() and Trigger() functions to manage this
	* @see On()
	* @see Off()
	* @see Trigger()
	*/
	var $hooks = array();

	/**
	* List of things to cache the return value of
	* @var array
	*/
	var $cache = array( // What to cache
		'get' => 1,
		'getby' => 1,
		'getall' => 0,
		'count' => 0,
	);

	/**
	* Cache object list. This is usually in the form $_cache[function][id] where function usually is something like 'get'
	* @var array
	* @access private
	*/
	var $_cache = array(); // Actual cache storage

	/**
	* Cached list (computed from $schema) that contains all fields NOT to output
	* @var array
	* @access private
	*/
	var $_hides = array();

	// Magic functions inc. Constructor {{{
	function __construct() {
		parent::__construct();
		$this->LoadHooks();
	}

	function __call($method, $params) {
		if (preg_match('/^getby(.+)$/i', $method, $matches)) // Make getBy* resolve to GetBy()
			return $this->GetBy(strtolower($matches[1]), $params[0]);
		if ($method == 'construct')
			return;
		trigger_error("Method $method does not exist");
	}
	// }}}
	// Schema handing functions {{{
	/**
	* Load the schema by calling DefineSchema() on the downstream object
	*/
	function LoadSchema() {
		if ($this->schema) // Already loaded
			return;
		$this->schema = $this->DefineSchema();
		$this->ReloadSchema();
	}

	/**
	* Reload the schema object along with all processing thats required
	* This function is usually automatically called by the function that needs it - LoadSchema(), SetSchema() etc.
	* @see LoadSchema
	* @see SetSchema
	*/
	function ReloadSchema() {
		// Sanity checks on schema {{{
		if (!$this->schema)
			trigger_error("No schema returned for model {$this->model} from DefineSchema()") && die();
		if (!isset($this->schema['_id']))
			trigger_error("_id is unset for model {$this->model} via DefineSchema. It must be a string pointer to the ID or the schema structure.") && die();

		if (isset($this->schema['_model'])) {
			$this->model = $this->schema['_model'];
			unset($this->schema['_model']);
		} elseif (!$this->model)
			trigger_error("Model is not set") && die();

		if (isset($this->schema['_table'])) {
			$this->table = $this->schema['_table'];
			unset($this->schema['_table']);
		} elseif (!$this->table)
			trigger_error("Table is not set for model {$this->model}") && die();
		// }}}
		$this->Trigger('setschema', $this->schema);
		// Map _id to whatever its pointing at {{{
		if (isset($this->schema['_id']) && is_string($this->schema['_id'])) {
			if (!isset($this->schema[$this->schema['_id']]))
				trigger_error("_id for model {$this->model} pointing to non-existant field {$this->schema['_id']}") && die();
			$this->schema['_id'] =& $this->schema[$this->schema['_id']];
		}
		// }}}
		// Process the $this->schema array {{{
		$this->_hides = array();
		foreach ($this->schema as $key => $attrib) {
			if (substr($key,0,1) == '_') // Is a meta field - skip
				continue;
			$this->schema[$key]['field'] = $key; // Map 'field' to the ID of each field

			// Deal with types {{{
			if (!isset($this->schema[$key]['type'])) {
				$this->schema[$key]['type'] = 'varchar';
			} else if ($this->schema[$key]['type'] == 'pk') { // All PK fields automatically become readonly
				$this->schema[$key]['allowcreate'] = false;
				$this->schema[$key]['allowsave'] = false;
			}
			// }}}

			// .readonly {{{
			if (isset($this->schema[$key]['readonly']) && $this->schema[$key]['readonly']) { // readonly is an alias for allowsave
				$this->schema[$key]['allowsave'] = false;
				unset($this->schema[$key]['readonly']);
			}
			// }}}

			// .hide {{{
			if (isset($this->schema[$key]['hide']) && $this->schema[$key]['hide']) { // hide is an alias for allowget
				$this->schema[$key]['allowget'] = false;
				unset($this->schema[$key]['hide']);
			}
			// }}}

			// .allowget {{{
			if (isset($this->schema[$key]['allowget']) && !$this->schema[$key]['allowget'])
				$this->_hides[] = $key;
			// }}}
		}
		// }}}
	}

	/**
	* Get an idealized version of the schema
	* This is usually the same as $schema but with some extra stuff added like _model, _table
	* @return array The array structure representing the schema tree
	*/
	function GetSchema() {
		$this->LoadSchema();
		$schema = array(
			'_model' => $this->model,
			'_table' => $this->table,
		);
		$schema = array_merge($schema, $this->schema);
		$schema['_id'] = $this->schema['_id']['field'];
		$this->Trigger('getschema', $schema);
		return $schema;
	}

	/**
	* Force the setting of a schema from an object
	* @param array $schema The schema array to load
	*/
	function SetSchema($schema) {
		$this->schema = $schema;
		$this->ReloadSchema();
	}

	/**
	* Return an input array filtered by fields we are allowed to perform an operation on
	* @param array $array The incomming hash of field values to filter
	* @param string $operation The operation to perform. Can be: where, set, get
	*/
	function FilterFields($array, $operation = 'where') {
		$out = array();
		foreach ($array as $field => $value) {
			if ($operation == 'where' && preg_match('/^(.*) (.*)$/', $field, $matches)) { // special CI syntax e.g. 'status !=' => 'something' (only for 'where' operations)
				$key = $matches[1];
				$cond = $matches[2];
				$val = $value;
			} else {
				$key = $field;
				$cond = '=';
				$val = $value;
			}

			if (!isset($this->schema[$key])) // Field definition does not exist at all
				continue;

			if ( // Is read-only during a 'set'
				$operation == 'set' &&
				isset($this->schema[$key]['allowget']) &&
				!$this->schema[$key]['allowget']
			)
				continue;

			if ( // Is not filterable by
				$operation == 'where' &&
				isset($this->schema[$key]['allowquery']) &&
				!$this->schema[$key]['allowquery']
			)
				continue;

			// If we got this far its a valid field
			$out[$field] = $value;
		}
		return $out;
	}
	// }}}
	// Hook functions {{{
	function LoadHooks() {
		if (isset($GLOBALS['model_hooks']))
			$GLOBALS['model_hooks']($this);
	}

	function On($event, $function, $method = 'replace') {
		if (isset($this->hooks[$event])) {
			if ($method == 'replace') {
				$this->hooks[$event] = array($function);
			} elseif ($method == 'append') {
				$this->hooks[$event][] = $funciton;
			} elseif ($method == 'prepend') {
				unshift($this->hooks[$event][], $funciton);
			}
		} else
			$this->hooks[$event] = array($function);
	}

	function Off($event) {
		if (isset($this->hooks[$event]))
			unset($this->hooks[$event]);
	}

	function Trigger($event, &$a = null, &$b = null, &$c = null) {
		if (!isset($this->hooks[$event]) || !$this->hooks[$event])
			return;

		foreach ($this->hooks[$event] as $func)
			$func($a, $b, $c);
	}
	// }}}
	// Cache handling functions {{{
	function UseCache($type) {
		return (isset($this->cache[$type]) && $this->cache[$type]);
	}

	function GetCache($type, $id) {
		if (!isset($this->cache[$type]) || !$this->cache[$type]) // Dont cache this type of call
			return FALSE;
		if (isset($this->_cache[$type][$id]))
			return $this->_cache[$type][$id];
		return FALSE;
	}
	
	function SetCache($type, $id, $value) {
		if (!isset($this->cache[$type]) || !$this->cache[$type]) // Dont cache this type of call
			return $value;
		if (!isset($this->_cache[$type]))
			$this->_cache[$type] = array();
		$this->_cache[$type][$id] = $value;
		return $this->_cache[$type][$id];
	}
	// }}}

	/**
	* Retrieve a single item by its ID
	* Calls the 'get' trigger on the retrieved row
	* @param mixed|null $id The ID (usually an Int) to retrieve the row by
	* @return array The database row
	*/
	function Get($id) {
		$this->LoadSchema();
		if ($value = $this->GetCache('get', $id))
			return $value;

		$this->db->from($this->table);
		$this->db->where($this->schema['_id']['field'], $id);
		$this->db->limit(1);
		$row = $this->db->get()->row_array();
		if ($row)
			$this->ApplyRow($row);
		return $this->SetCache('get', $id, $row);
	}

	/**
	* Retrieve a single item by a given field
	* This is an assistant function to the magic function that allows 'GetBy$FIELD' type calls
	* e.g. GetBy('email', 'matt@mfdc.biz') OR GetByEmail('matt@mfdc.biz')
	* @param string $param A single field to retrieve data by
	* @param mixed $value The criteria to search by
	* @return array The first matching row that matches the given criteria
	*/
	function GetBy($param, $value) {
		$this->LoadSchema();
		if ($cacheval = $this->GetCache('getby', $cacheid = "$param-$value"))
			return $cacheval;

		$this->db->from($this->table);
		$this->db->where($param, $value);
		$this->db->limit(1);
		$row = $this->db->get()->row_array();
		if ($row)
			$this->ApplyRow($row);
		return $this->SetCache('getby', $cacheid, $row);
	}

	/**
	* Retrieves multiple items filtered by where conditions
	* Calls the 'getall' trigger to apply additional filters
	* Calls the 'get' trigger on each retrieved row
	* @param array $where Additional where conditions to apply
	* @param string $orderby The ordering criteria to use
	* @param int $limit The limit of records to retrieve
	* @param int $offset The offset of records to retrieve
	* @return array All found database rows
	*/
	function GetAll($where = null, $orderby = null, $limit = null, $offset = null) {
		$this->LoadSchema();
		if ($this->UseCache('getall')) {
			$params = func_get_args();
			$cacheid = md5(json_encode($params));

			if ($value = $this->GetCache('getall', $cacheid))
				return $value;
		}

		$this->Trigger('getall', $where, $orderby, $limit, $offset);

		$this->db->from($this->table);

		if ($where = $this->FilterFields($where, 'where'))
			$this->db->where($where);
		if ($orderby)
			$this->db->order_by($orderby);
		if ($limit || $offset)
			$this->db->limit($limit,$offset);

		$out = array();
		foreach ($this->db->get()->result_array() as $row) {
			$this->ApplyRow($row);
			$out[] = $row;
		}

		$this->Trigger('rows', $out);

		return isset($cacheid) ? $this->SetCache('getall', $cacheid, $out) : $out;
	}

	/**
	* Shorthand function to run GetAll() and run a callback function on each return
	* @param array $where A GetAll() compatible where condition
	* @param callback $callback Callback to call on each matching item. Callback will take the form `function(&$row)`. If $row is boolean FALSE or the function returns FALSE the return of Each() will be omitted - similar to Map()
	* @return array This function will always return an array, blank if nothing matched or populated if something did in a similar style to Map()
	*/
	function Each($where, $callback) {
		$out = array();
		$rows = $this->GetAll($where);
		if (!$rows)
			return $out;
		foreach ($rows as $row) {
			$return = $callback($row);
			if ($return === false || !$row) // We got (deep-checked) FALSE or row was mangled into nothing - skip
				continue;
			$out[] = $row;
		}
		return $out;
	}

	/**
	* Alias for Each
	* @see Each()
	*/
	function Map($where, $callback) {
		return $this->Each($where, $callback);
	}

	/**
	* Shorthand function to return all the available options for a given field
	* This will only really be useful for [type=enum] as its the only one that should return something useful anyway
	* @param string $field The field to return the available options for
	*/
	function GetOptions($field) {
		$this->LoadSchema();
		if (
			isset($this->schema[$field]) && // A valid field
			isset($this->schema[$field]['options']) // Valid options
		)
			return $this->schema[$field]['options'];
	}

	/**
	* Called on each row after a Get() or GetAll() call to mangle the data provided back to the client
	* This function also applies the 'hide' directive for all rows to remove the outgoing data
	* Calls the 'get' trigger
	* @param array &$row The row to call the 'row' trigger on
	* @see Get()
	* @see GetAll()
	* @see ApplyRows()
	* @return array The mangled database row
	*/
	function ApplyRow(&$row) {
		$this->LoadSchema();
		$this->Trigger('row', $row);
		if (!$this->_hides)
			return;
		foreach ($this->_hides as $field)
			if (isset($row[$field]))
				unset($row[$field]);
	}

	/**
	* Convenience function to walk over an array-of-hashes and apply Row() to each member
	* This function can work with hashes as well as arrays so either an array-of-hashes or hash-of-hashes are both fine
	* @param array &$rows An array of hashes where the 'Row' call will be applied to each
	*/
	function ApplyRows(&$rows) {
		foreach($rows as $key => $row) {
			$this->ApplyRow($rows[$key]);
		}
	}

	/**
	* Similar to GetAll() this function just returns a count of records that would be returned
	* Calls the 'getall' trigger to apply additional filters
	* @see GetAll()
	* @return int The number of records matching the where condition
	*/
	function Count($where = null) {
		$this->LoadSchema();
		if ($this->UseCache('count')) {
			$cacheid = md5(json_encode($where));

			if ($value = $this->GetCache('count', $cacheid))
				return $value;
		}

		$this->Trigger('getall', $where);

		$this->db->select('COUNT(*) AS count');
		$this->db->from($this->table);
		if ($where = $this->FilterFields($where, 'where'))
			$this->db->where($where);
		$row = $this->db->get()->row_array();

		return isset($cacheid) ? $this->SetCache('count', $cacheid, $row['count']) : $row['count'];
	}

	/**
	* Wrapper around Save() to test if an ID is specified if not Create() is called instead
	* @param array $data A hash of data to save, if the primary key is omitted Create() is called, if present Save() is called
	*/
	function SaveCreate($data) {
		if (!$data)
			return;

		if (!is_array($data))
			die('Joyst_Model#SaveCreate()> Data must be a hash');

		$this->LoadSchema();

		if (isset($data[$this->schema['_id']['field']])) { // ID present?
			$id = $data[$this->schema['_id']['field']];
			unset($data[$this->schema['_id']['field']]); // Remove ID from saving data (it will only be removed during filtering anyway as PKs can never be saved)
			return $this->Save($id, $data);
		} else { // ID not present - use create
			return $this->Create($data);
		}
	}

	/**
	* Attempt to create a database record using the provided data
	* Calls the 'create' trigger on the data before it is saved
	* @param array $data A hash of data to attempt to store
	* @return int|null Either the successful ID of the new record or null for failure
	*/
	function Create($data) {
		if (!$data)
			return;
		$this->LoadSchema();

		$this->Trigger('create', $data);
		if (! $data = $this->FilterFields($data, 'set')) // Nothing to save
			return FALSE;

		$this->db->insert($this->table, $data);
		$id = $this->db->insert_id();

		$this->Trigger('created', $id, $data);
		return $id;
	}

	/**
	* Attempt to save a database record using the provided data
	* Calls the 'save' trigger on the data before it is saved
	* @param mixed|array $id The ID to use to identify the record to change or the full row to save (data will be ignored)
	* @param array $data A hash of data to attempt to store (optional if ID is the full row)
	* @return array|null Either the actual data saved (after mangling) or null
	*/
	function Save($id, $data = null) {
		if (!$id)
			return;

		$this->LoadSchema();

		if (is_array($id)) {
			$data = $id;
			if (!isset($data[$this->schema['_id']['field']])) // Incomming data has no ID to address by
				return;
			$id = $data[$this->schema['_id']['field']];
			unset($data[$this->schema['_id']['field']]); // Remove ID from saving data (it will only be removed during filtering anyway as PKs can never be saved)
		}

		if (!$data)
			return;

		$this->trigger('save', $id, $data);

		if (! $data = $this->FilterFields($data, 'set')) // Nothing to save
			return FALSE;

		$this->db->where($this->schema['_id']['field'], $id);
		$this->db->update($this->table, $data);

		$this->Trigger('saved', $id, $save);
		return $save;
	}

	/**
	* Delete a single item by its ID
	* Calls the 'delete' trigger on the retrieved row
	* @param mixed|null $id The ID (usually an Int) to retrieve the row by
	* @return bool The success of the delete operation
	*/
	function Delete($id) {
		$this->LoadSchema();

		$this->Trigger('delete', $id);
		if (!$id)
			return FALSE;

		$this->db->from($this->table);
		$this->db->where($this->schema['_id']['field'], $id);
		$this->db->delete();

		$this->Trigger('deleted', $id);
		return TRUE;
	}

	/**
	* Delete a number of records by a complex GetAll() compatible expression
	* Calls the 'deleteall' trigger to apply additional filters
	* This function really just wraps GetAll() and Delete() together
	* @see GetAll()
	* @see Delete()
	* @see Each()
	* @return int The number of records removed successfully
	*/
	function DeleteAll($where = null, $orderby = null) {
		$this->LoadSchema();

		$this->Trigger('deleteall', $where, $orderby);

		if (!$rows = $this->GetAll($where, $orderby))
			return;

		$success = 0;
		foreach($rows as $row)
			if ($this->Delete($row[$this->schema['_id']['field']]))
				$success++;

		return (int) $success;
	}

	function Debug() {
		$args = func_get_args();
		echo "Joyst_model#Debug():" . print_r($args, 1);
		die();
	}
}
