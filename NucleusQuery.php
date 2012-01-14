<?php

namespace Nucleus;

class Query {
	private $connection;

	private $queries = array();       // Each query run by this object
	private $select = array();        // The requested selections
	private $from = array();          // The primary table to pull from
	private $tables = array();        // Every table referenced in this query
	private $joins = array();         // The requested joins (indexed by name)
	private $where = array();         // Any defined where statements
	private $orderby = array();       // The requested order

	// ------------------------------------------------------------------------

	public function __construct($connection=FALSE) {
		$this->connection = $connection ?: Connection::active();
		$this->reset();
	}

	// ------------------------------------------------------------------------

	public function reset() {
		$defaults = (object)get_class_vars('\Nucleus\Query');
		$this->select = $defaults->select;
		$this->from = $defaults->from;
		$this->tables = $defaults->tables;
		$this->joins = $defaults->joins;
		$this->where = $defaults->where;
		$this->orderby = $defaults->orderby;
	}

	// ------------------------------------------------------------------------

	public function select($key) {
		if (is_array($key)) {
			foreach ($key as $k) {
				$this->select($k);
			}
		}
		else if (is_string($key)) {
			$this->select[] = $key;
		}
		return $this;
	}

	public function build_select() {
		$select = array();
		foreach ($this->from as $identifier => $table) {
			$columns = $this->query("DESCRIBE {$table}");

			if (!$columns) {
				throw new \Exception('Could not build SELECT, invalid table specified', 500);
			}

			foreach($columns as $column) {
				$field = $column['Field'];
				if (in_array($field, $this->select) || !$this->select) {
					$select[] = "{$identifier}.{$field} AS `{$identifier}.{$field}`";
				}
			}
		}
		return ' SELECT '.implode(', ', $select);
	}

	// ------------------------------------------------------------------------

	public function from($table, $alias=FALSE) {
		$key = $this->add_table($table, $alias, TRUE);
		$this->from[$key] = $table;
		return $this;
	}

	public function build_from() {
		$sql = ' FROM ';
		foreach ($this->from as $key => $table) {
			$sql.= "{$table} AS {$key}";
		}
		return $sql;
	}

	// ------------------------------------------------------------------------

	public function join($foreign_table, $c=array()) {
	
		// Determine the tables we're trying to relate here
		preg_match('/^(?:(.*?)\.)?(.*)$/', $foreign_table, $matches);
		$c['primary_table'] = $matches[1]?:$this->primary_table();
		$c['primary_id'] = $this->table_identifier_for($c['primary_table']);
		$c['foreign_table'] = $matches[2];
		$c['foreign_id'] = $this->add_table($c['foreign_table']);
		$c['connection'] = $this->connection;

		if (($join = \Nucleus\JoinOne::check($c)) !== FALSE || 
		    ($join = \Nucleus\JoinMany::check($c)) !== FALSE || 
		    ($join = \Nucleus\JoinManyMany::check($c)) !== FALSE) {
		    
			$this->joins[$join->primary_id().'.'.$join->as()] = $join;
		}

		return $this;
	}

	public function build_joins() {
		$sql = '';

		foreach ($this->joins as $key => $config) {
			$sql.= $join->sql();
		}

		return $sql;
	}

	// ------------------------------------------------------------------------

	public function where($key, $value) {
		if (func_num_args() == 2) {
			$key = func_get_arg(0);
			$operator = '=';
			$value = func_get_arg(1);
		}
		if (func_num_args() == 3) {
			$key = func_get_arg(0);
			$operator = func_get_arg(1);
			$value = func_get_arg(2);
		}

		if ($value === TRUE) { $value = 1; }
		if ($value === FALSE) { $value = 0; }
		if ($value === NULL) { $operator = 'IS'; $value = 'NULL'; }

		$this->where[$key] = array(
			'operator' => $operator,
			'value' => $value
		);

		return $this;
	}

	public function build_where() {
		$sql = '';
		if ($this->where) {
			$sql.= ' WHERE ';

			foreach ($this->where as $key => $w) {
				$sql.= "{$key} {$w['operator']} :{$key}";
			}
		}
		return $sql;
	}

	public function build_where_hash() {
		$where = array();
		foreach ($this->where as $key => $w) {
			$where[$key] = $w['value'];
		}
		return $where;
	}

	// ------------------------------------------------------------------------

	public function orderby($key, $sort='asc') {
		$this->orderby[] = "{$key} {$sort}";
		return $this;
	}

	public function build_orderby() {
		$sql = '';
		if ($this->orderby) {
			$sql.= ' ORDER BY ';
			$sql.= implode(', ', $this->orderby);
		}
		return $sql;
	}

	// ------------------------------------------------------------------------

	public function get($table) {
		return $this->from($table)->go();
	}

	// ------------------------------------------------------------------------

	private function _build_query() {
		$sql = $this->build_select();
		$sql.= $this->build_from();
		$sql.= $this->build_joins();
		$sql.= $this->build_where();
		$sql.= $this->build_orderby();
		return trim($sql);
	}

	public function go() {
		$result = new \Nucleus\Result(
			clone $this,
			$this->query()
		);
		$this->reset();
		return $result;
	}

	private function query($sql=FALSE) {
		if (!$sql) {
			$sql = $this->_build_query();
		}
		$this->queries[] = $sql;
		$statement = $this->connection->prepare($sql);
		if (!$statement->execute($this->build_where_hash())) {
			throw new \Exception(
				$statement->errorInfo()."\n".$this->last_query(),
				500
			);
		}
		$r = $statement->fetchAll(\PDO::FETCH_ASSOC);
		return $r;
	}

	// ------------------------------------------------------------------------

	public function primary_table() {
		$keys = array_keys($this->tables);
		return @$this->tables[$keys[0]]?:FALSE;
	}

	public function primary_table_identifier() {
		$keys = array_keys($this->tables);
		return @$keys[0]?:FALSE;
	}

	public function add_table($table, $alias=FALSE, $primary=FALSE) {
		$key = $alias?:'t'.count($this->tables);
		$this->tables[$key] = $table;
		return $key;
	}

	public function table_identifier_for($table_name) {
		return array_search($table_name, $this->tables);
	}

	public function join_config($key=FALSE) {
		return @$this->joins[$key];
	}

	public function join_for_foreign_id($identifier) {
		foreach ($this->joins as $join) {
			if ($join['foreign_id'] == $identifier) {
				return $join;
			}
		}

		return FALSE;
	}

	// ------------------------------------------------------------------------

	public function current_query() {
		return $this->_build_query();
	}

	public function last_query() {
		return @$this->queries[count($this->queries)-1]?:FALSE;
	}

	// ------------------------------------------------------------------------

	
}
