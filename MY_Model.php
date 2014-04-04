<?
/**
* Triggers:
*	create($data) - Used before the insert call in a DB
*	get($row) - Mangling function to rewrite a record (used in Get() and GetAll() functions per row)
*	getall($active_record) - Used to add additional default parameters to the GetAll() function before it runs
*	save($data) - Used before the update call in a DB
*	schema($schema) - Schema is loaded
*/
class MY_Model extends CI_Model {
	var $model = '';
	var $table = '';
	var $schema = array();
	var $hooks = array();

	var $cache = array( // What to cache
		'get' => 1,
		'getby' => 1,
		'getall' => 0,
		'count' => 0,
	);
	var $_cache = array(); // Actual cache storage

	var $_hides = array();

	function __construct() {
		parent::__construct();
		$this->LoadHooks();
	}

	function __call($method, $params) {
		if (preg_match('/^getby(.+)$/i', $method, $matches)) // Make getBy* resolve to GetBy()
			return $this->GetBy(strtolower($matches[1]), $params[0]);
	}

	// Schema loader / reloader {{{
	function LoadSchema() {
		if ($this->schema) // Already loaded
			return;
		$this->schema = $this->DefineSchema();
		$this->ReloadSchema();
	}

	function ReloadSchema() {
		// Sanity checks on schema {{{
		if (!$this->schema)
			trigger_error("No schema returned for model {$this->model} from DefineSchema()") && die();
		if (!isset($this->schema['_id']))
			trigger_error("_id is unset for model {$this->model} via DefineSchema. It must be a string pointer to the ID or the schema structure.") && die();

		if (isset($this->schema['_model'])) {
			$this->table = $this->schema['_model'];
			unset($this->schema['_model']);
		} elseif (!$this->model)
			trigger_error("Model is not set") && die();

		if (isset($this->schema['_table'])) {
			$this->table = $this->schema['_table'];
			unset($this->schema['_table']);
		} elseif (!$this->table)
			trigger_error("Table is not set for model {$this->model}") && die();
		// }}}
		$this->schema = $this->Trigger('schema', $this->schema);
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
			if ($this->schema[$key]['type'] == 'pk') { // All PK fields automatically become readonly
				$this->schema[$key]['readonly'] = true;
			}
			if (isset($this->schema[$key]['hide']) && $this->schema[$key]['hide'])
				$this->_hides[] = $key;
		}
		// }}}
	}
	// }}}
	// Hook functions {{{
	function LoadHooks() {
		if (isset($GLOBALS['model_hooks']))
			foreach ($GLOBALS['model_hooks'] as $key => $val)
				$this->On($key, $val);
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

	function Trigger($event) {
		$params = func_get_args();
		array_shift($params);
		$payload = isset($params[0]) ? $params[0] : null;

		if (!isset($this->hooks[$event]) || !$this->hooks[$event])
			return $payload;

		foreach ($this->hooks[$event] as $func) {
			$payload = call_user_func_array($func, $params);
			$params[0] = $payload;
		}
		return $payload;
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
		$this->Populate($row);
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
		echo $this->db->_compile_select();
		$row = $this->db->get()->row_array();
		$out = $this->Populate($row);
		return $this->SetCache('getby', $cacheid, $out);
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

		$this->db->from($this->table);
		if ($where)
			$this->db->where($where);
		if ($orderby)
			$this->db->order_by($orderby);
		if ($limit || $offset)
			$this->db->limit($limit,$offset);

		$this->db = $this->Trigger('getall', $this->db);

		$out = array();
		foreach ($this->db->get()->result_array() as $row)
			$out[] = $this->Populate($row);

		return isset($cacheid) ? $this->SetCache('getall', $cacheid, $out) : $out;
	}

	/**
	* Called on each row after a Get() or GetAll() call to mangle the data provided back to the client
	* This function also applies the 'hide' directive for all rows to remove the outgoing data
	* Calls the 'get' trigger
	* @see Get()
	* @see GetAll()
	* @return array The mangled database row
	*/
	function Populate($row) {
		$row = $this->Trigger('get', $row);
		if (!$this->_hides)
			return $row;
		foreach ($this->_hides as $field)
			if (isset($row[$field]))
				unset($row[$field]);
		return $row;
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

		$this->db->select('COUNT(*) AS count');
		$this->db->from($this->table);
		if ($where)
			$this->db->where($where);
		$this->db = $this->Trigger('getall', $this->db);
		$row = $this->db->get()->result_array();

		return isset($cacheid) ? $this->SetCache('count', $cacheid, $row['count']) : $row['count'];
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
		$save = $this->SetData($data);

		if (!$save) // Nothing to save
			return FALSE;

		if (! $save = $this->trigger('create', $save))
			return FALSE;

		$this->db->insert($this->table, $save);
		return $this->db->insert_id();
	}

	/**
	* Attempt to save a database record using the provided data
	* Calls the 'save' trigger on the data before it is saved
	* @param mixed $id The ID to use to identify the record to change
	* @param array $data A hash of data to attempt to store
	* @return array|null Either the actual data saved (after mangling) or null
	*/
	function Save($id, $data) {
		if (!$id || !$data)
			return;

		$this->LoadSchema();

		$save = $this->SetData($data);

		if (!$save) // Nothing to save
			return FALSE;

		if (! $save = $this->trigger('save', $save))
			return FALSE;

		$this->db->where($this->schema['_id']['field'], $id);
		$this->db->update($this->table, $save);
		return $save;
	}

	/**
	* Internal function used by Create() and Save() to determine exactly what fields to save
	* @param array $data The incomming data to filter
	* @return array The outgoing data that is safe to save to the DB
	*/
	function SetData($data) {
		$save = array();
		foreach ($data as $field => $value) {
			if (
				isset($this->schema[$field]) && // Is defined
				(
					!isset($this->schema[$field]['readonly']) || // No RO specified OR
					!$this->schema[$field]['readonly'] // Its off anyway
				)
			)
				$save[$field] = $value;
		}
		return $save;
	}
}
