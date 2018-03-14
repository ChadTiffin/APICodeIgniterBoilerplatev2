<?php

class Base_Model extends CI_Model {
		
	public $table = "";
	public $soft_delete = false;
	public $page_limit = 50; //optionally over-ride in child model
	public $validation_rules = [];
	public $non_api_methods = []; //any method listed here will not be available as an API endpoint
	public $hidden_fields = []; //any fields that shouldn't go out in the api response
	public $module_name = "";

	public $default_orders = []; //(optional) set default order for gets
	
	//hide entire model from accessible api. Used as an internal model only. An internal model may still be output to the API as a child model of another. It just cannot be queried directly as a resource endpoint
	public $internal_model_only = false; 

	/* 
	$relations expects = [
		[
			'model' => {relatedModelName},
			'key' => {table_key ex user_id},
			'joins' => [
				['table' => {table}, 'key' => {key}].
			'hasMany' => true (optional, if not set, single child record assumed),
			'lookupTable' => {lookup_table_name}, //set if model has many records through a lookup table (Many to Many),
			'lookupTableSelfKey' => {key} key the lookup key uses to refer back to this model (ex. if declared in User, this might be user_id)
			'explicit_key' => (optional) string what the model will get assigned to
		]
	]
	*/
	public $relations = [];

	public $user_id = null; //The user id that is performing the model request
	public $has_full_perms = false; //if true, record level permissions don't need to be checked

	//take a list of relations, and filter the model's relations property to what's only specified in $relations
	private function filterRelations($relations) {
		
		$filtered = [];
		if ($relations == "all") {
			foreach ($this->relations as $model_rel) {
				$filtered[] = $model_rel;
			}
		}
		else {
			
			foreach ($relations as $rel) {
				foreach ($this->relations as $model_rel) {
					if (is_array($rel)) {
						if ($rel['model'] == $model_rel['model'])
							$filtered[] = $model_rel;
					}
					else {
						if ($rel == $model_rel['model'])
							$filtered[] = $model_rel;
					}
				}
			}
		}

		return $filtered;
	}

	/**
	*	ATTACH SPECIFIED CHILD MODELS TO A RESULT SET
	*	@param $result: Parent's result set so we can iterate through and attach a child record
	*	@param $relations: An array of child model names ['User','Job']
	*	@param $params: Need to pass in parent parameters to filter for tenant_id
	**/
	private function appendChildren($result,$relations,$params=null) {
		if ($result) {

			foreach ($relations as $model) {

				$model_name = $model['model'];
				$this->load->model($model_name);

				//pass down requesting user_id
				$this->$model_name->user_id = $this->user_id;

				//assign permissions to the child model
				if ($this->$model_name->module_name == "")
					$this->$model_name->has_full_perms = true;

				//just assign automatic permission to childlren: Might be a better way, just haven't figured out what it is yet
				$this->$model_name->has_full_perms = true;

				if (isset($model['hasMany'])) {
					//return a collection, so use get()

					if (isset($model['lookupTable']) && isset($model['lookupTableSelfKey'])) {
						
						//if tenant_id is set, add a filter it to make sure no leaks of other tenant data get in
						if (isset($params['tenant_id']) && $this->db->field_exists("tenant_id",$this->$model_name->table)) 
							$this->db->where($this->$model_name->table.".tenant_id",$params['tenant_id']);

						$this->db
							->select("*")
							->from($this->$model_name->table)
							->join($model['lookupTable'],$model['lookupTable'].".".$model['key']."=".$this->$model_name->table.".id")
							->where($model['lookupTable'].".".$model['lookupTableSelfKey'],$result['id']);

						if ($this->$model_name->soft_delete)
							$this->db->where("deleted",0);

						$childset = $this->db->get()->result_array();
					}
					else {
						$params['filters'] = [
							[$model['key'],$result['id']]
						];

						//if tenant_id is set, add a filter it to make sure no leaks of other tenant data get in
						if (isset($params['tenant_id']) && $this->db->field_exists("tenant_id",$this->$model_name->table))
							$params['tenant_id'] = $params['tenant_id'];

						//var_dump($params);

						$childset = $this->$model_name->get($params);
					}
					
				}
				else {
					//return a single record, so use find(), and make sure field is set to 'id' so it doesn't look for the slug

					//get child params
					foreach ($params as $key => $param) {
						if ($key == "include") {

							if (gettype($param) == "string")
								$childParam = json_decode($param,true);
							else
								$childParam = $param;

							foreach ($childParam as $childModel) {

								if (isset($childModel['include'])) {
									$childInclude = $childModel['include'];
								}
							}
						}
					}

					$findParams = [
						'field' => "id"
					];

					if (isset($childInclude)) 
						$findParams['include'] = $childInclude;

					$childset = $this->$model_name->find($result[$model['key']],$findParams);

				}

				if (isset($model['explicit_key']))
					$result[$model['explicit_key']] = $childset;
				else
					$result[$model['model']] = $childset;
			}

		}
		return $result;
	}

	/**
	*	FIND A SINGLE RECORD BY ID, or by field by setting $params['field']

	ACCEPTS:
        include = ['relatedModel','relatedModel'],
	**/
	public function find($id,$params=[])
	{

		if (!$this->has_full_perms)
			return false;

		if ($this->db->field_exists('slug',$this->table))
			if (is_numeric($id))
				$where['id'] = $id;
			else
				$where['slug'] = $id;

		elseif (isset($params['field']))
			$where[$params['field']] = $id;
		else
			$where['id'] = $id;

		if (isset($params['tenant_id']))
			$this->db->where("tenant_id",$params['tenant_id']);

		if ($this->soft_delete) 
			$where["deleted"] = 0;

		$result = $this->db->where($where)->get($this->table)->row_array();

		foreach ($this->hidden_fields as $field) 
			unset($result[$field]);

		if (isset($params['include'])) {

			$relations = "all";
			if ($params['include'] != 'all') {
				if (gettype($params['include']) == "string")
					$relations = json_decode($params['include'],true);
				else
					$relations = $params['include'];
			}

			$rels_to_include = [];

			$result = $this->appendChildren($result, $this->filterRelations($relations),$params);
		}

		return $result;
	}

	/**
	*	RETRIEVE A COLLECTION OF RECORDS

    ACCEPTS:
        filters = [
        	[field, value]
            [field operator, value],
            [field, value, 'and/or'],
            [field, value,'and/or','like']
        ],
        page: int,
        orders = [
            [field, 'ASC/DESC']
        ],
        include = ['relation','relation'],
    **/

	public function get($params = [])
	{

		if (!$this->has_full_perms)
			return [];

		$this->db
			->select($this->table.".*")
			->from($this->table);

		////////////////////////////
		// ORDERING
		////////////////////////////

		$orders = [];
		if (isset($params['orders'])) 
			$orders = json_decode($params['orders'],true);
		
		else if ($this->default_orders)
			$orders = $this->default_orders;

		foreach ($orders as $order) 
			$this->db->order_by($order[0],$order[1]);

		/////////////////////////
		// FILTERING
		/////////////////////////

		$filters = [];
		if (isset($params['filters'])) {
			if (gettype($params['filters']) == "string")
				$filters = json_decode($params['filters'],true);
			else
				$filters = $params['filters'];
		}

		//do not include deleted records if soft delete enabled on model
		if ($this->soft_delete)
			$filters[] = ['deleted',0];

		if (isset($params['tenant_id']) && $this->db->field_exists("tenant_id",$this->table) && $this->table != "users") //exception for User Model
			$this->db->where("tenant_id",$params['tenant_id']);

		$child_model_filters = [];

		foreach ($filters as $filter) {

			if (strpos($filter[0], ".") !== false) {
				//filter contains a period, so this is a filter based on a child model value
				$child_model_filters[] = $filter;
			}
			else {

				if (isset($filter[2]) && strtolower($filter[2]) == 'and') {
					if (isset($filter[3]) && strtolower($filter[3]) == 'like')
						$this->db->like($filter[0],$filter[1]);
					else
						$this->db->where($filter[0],$filter[1]);
				}
				elseif (isset($filter[2]) && strtolower($filter[2]) == 'or') {
					if (isset($filter[3]) && strtolower($filter[3]) == 'like')
						$this->db->or_like($filter[0],$filter[1]);
					else
						$this->db->or_where($filter[0],$filter[1]);
				}
				else
					$this->db->where($filter[0],$filter[1]);
			}
		}

		///////////////////////
		// PAGINATION
		///////////////////////

		if (isset($params['page']) && is_numeric($params['page']))
			$this->db->limit($this->page_limit, ($params['page']-1) * $this->page_limit);

		$results = $this->db->get()->result_array();

		if ($this->hidden_fields) {
			$mutated_results = [];
			foreach ($results as $result) {
				foreach ($this->hidden_fields as $field) 
					unset($result[$field]);

				$mutated_results[] = $result;
			}
			$results = $mutated_results;
		}

		////////////////////////////
		// APPENDING RELATIONS
		////////////////////////////
		$with_relations = [];

		if ($this->relations && isset($params['include'])) {
			//has relations, so we'll iterate each declared child and nest them into the result with a key of {ModelName}

			$relations = "all";
			if ($params['include'] != 'all') 
				$relations = json_decode($params['include'],true);

			foreach ($results as $result)
				$with_relations[] = $this->appendChildren($result, $this->filterRelations($relations),$params);

			if (count($child_model_filters) > 0) {
				//we have some filtering to apply based on values of child models
				$filtered_results = [];

				var_dump($child_model_filters);

				foreach ($child_model_filters as $filter) {
					
					$filter_array = explode(".", $filter[0]);
					$child_model = $filter_array[0];
					$child_field = $filter_array[1];

					foreach ($with_relations as $record) {

						if ($record[$child_model][$child_field] == $filter[1])
							$filtered_results[] = $record;
					}

				}

				$with_relations = $filtered_results;
			}
			
			return $with_relations;
		}
		else 
			return $results;
		
	}

	//

	//

	/**
	*	takes a model and array of ids and if a relation through a lookup table exists, 
	*	we'll overwrite what's in the lookup table with the new list of ids
	*
	*
	*	$other_id/$other_field allows you to pass in the id for a 3-way lookup table
	**/
	private function sync($model, $record_id,$array_of_ids,$other_field=null,$other_id=null) {
		$this->load->model($model);

		//get relation in relations list and check if its through a lookup table
		foreach ($this->relations as $relation) {

			if ($relation['model'] == $model && isset($relation['lookupTable'])) {
				//found it, delete matching records in lookup table

				if ($other_id && $other_field)
					$this->db->where($other_field,$other_id);

				$this->db->where($relation['lookupTableSelfKey'],$record_id)
					->delete($relation['lookupTable']);

				if ($other_field && $other_id) {
					//save new list now
					foreach ($array_of_ids as $id) {
						$this->db->insert($relation['lookupTable'],[
							$other_field => $other_id,
							$relation['lookupTableSelfKey'] => $record_id,
							$relation['key'] => $id
						]);
					}
				}
				else {
					//save new list now
					foreach ($array_of_ids as $id) {
						$this->db->insert($relation['lookupTable'],[
							$relation['lookupTableSelfKey'] => $record_id,
							$relation['key'] => $id
						]);
					}
				}

				
				return true;
				
			}
		}
		return false; //appropriate relationship wasn't found
	}

	//returns array of errors on fail, or integer of updated record on success
	/**
	*	@param $data: 
	*		Associative aray of field/values
	*		AN/OR
	*		Syncing many-to-many relations: Simply pass field name as the related model name, then an
	*			array of other model ids (ex. ["2","7","8"]). The previous records in the lookup table will
	*			be REPLACED with the passed array of ids
	*
	*		Syncing many-to-many relations on lookup table: 
	*			$childModel (2-way) = {modelName}: [id,id,id]
	*
	*				OR
	*
	*			$childModel (3-way) = {modelName}: {
	*									set: [id,id,id],
	*									other_field: {columnName},
	*									other_id: {id} //to save on the table
	*								}
	*
	*		
	**/
	public function save($data)
	{

		if (!$this->has_full_perms)
			return ["You do not have permission to write to this Resource"];

		$this->load->library("form_validation");
		$this->form_validation->set_error_delimiters('', '');

		$childrenToSync = [];

		//check for child models to save, otherwise strip any fields that don't exist
		foreach ($data as $field => $value) {

			if (!$this->db->field_exists($field,$this->table) &&
					file_exists(APPPATH."models/".$field.".php") && 
					is_array(json_decode($value,true))) {

				//this appears to be a child model, add to array of children to sync after we know if this is an insert or update (if its an insert, we'll need the created record's id first)

				$childrenToSync[] = [
					"model" => $field,
					"values" => json_decode($value,true)
				];

				unset($data[$field]);
			}
			//make sure not to unset if field is saveChildren otherwise we'll have problems
			elseif (!$this->db->field_exists($field,$this->table))
				unset($data[$field]);
		}

		$validation_passes = true;
		if ($this->validation_rules != null) {
			$this->form_validation->set_rules($this->validation_rules);
			$this->form_validation->set_data($data);
			$validation_passes = $this->form_validation->run();
		}

		if (!$validation_passes) {
			//fails
			$form_errors = [];
			foreach ($this->validation_rules as $field) {
				if (form_error($field['field']) != "")
					$form_errors[$field['field']] = form_error($field['field']);
			}
			return $form_errors;
		}
		else {

			if ($this->db->field_exists("updated_at",$this->table))
				$data['updated_at'] = date("Y-m-d H:i:s");

			if ($this->db->field_exists("updated_by",$this->table))
				$data['updated_by'] = $this->user_id;

			if (isset($data['id']) && $data['id'] != 'undefined' && $data['id'] != 0) {

				//make sure tenant_id is set
				if (isset($data['tenant_id']))
					$this->db->where("tenant_id",$data['tenant_id']);

				$this->db
					->set($data)
					->where('id',$data['id'])
					->update($this->table);
				$record_id = $data['id'];
			}
			else {
				if ($this->db->field_exists("created_at",$this->table))
					$data['created_at'] = date("Y-m-d H:i:s");

				if ($this->db->field_exists("created_by",$this->table))
					$data['created_by'] = $this->user_id;

				if ($this->db->field_exists("slug",$this->table) && !isset($data['slug']))
					$data['slug'] = sha1(time().json_encode($data));

				$this->db->insert($this->table,$data);
				$record_id = $this->db->insert_id();
			}

			//sync child models
			foreach ($childrenToSync as $child) {
				//check if a 3-way lookup structure (other field) has been passed

				if (isset($child['values']['other_field']) && isset($child['values']['other_id'])) {
					$this->sync($child['model'],$record_id,$child['values']['set'],$child['values']['other_field'],$child['values']['other_id']);
				}
				else {
					$this->sync($child['model'],$record_id,$child['values']);
				}

				
			}

			if (isset($data['slug']))
				return $data['slug'];

			return $record_id;
		}
	}

	//returns number of records saved
	public function save_batch($data){

		if (!$this->has_full_perms)
			return false;

		$records = json_decode($data['records'],true);

		$this->load->library("form_validation");
		$this->form_validation->set_error_delimiters('', '');
		$validation_passes = true;
		$form_errors = [];
		$cnt = 0;
		foreach ($records as $record) {
			$this->form_validation->set_data($record);

			//check to make sure any fields that don't exist are ignored
			foreach ($record as $field => $value) {
				if (!$this->db->field_exists($field,$this->table))
					unset($data[$field]);
			}
			
			$validation_passes = true;
			if ($this->validation_rules != null) {
				$this->form_validation->set_rules($this->validation_rules);
				$validation_passes = $this->form_validation->run();
			}
			if ($validation_passes) {

				if ($this->db->field_exists("updated_at",$this->table))
					$record['updated_at'] = date("Y-m-d H:i:s");

				if ($this->db->field_exists("updated_by",$this->table))
					$record['updated_by'] = $this->user_id;

				if (isset($record['id']) && $record['id'] != 'undefined' && $record['id'] != 0) {

					if (isset($data['tenant_id'])) {

						if (isset($data['tenant_id'])) {
							$this->db->where("tenant_id",$data['tenant_id']);
							$record['tenant_id'] = $data['tenant_id'];
						}

						if ($this->db->field_exists("updated_at",$this->table))
							$record['updated_at'] = date("Y-m-d H:i:s");

						//do the update
						$this->db
							->set($record)
							->where('id',$record['id'])
							->update($this->table);

						$cnt += $this->db->affected_rows();
						
					}
				}
				else {
					if ($this->db->field_exists("created_at",$this->table))
						$record['created_at'] = date("Y-m-d H:i:s");

					if ($this->db->field_exists("created_by",$this->table))
						$record['created_by'] = $this->user_id;

					if ($this->db->field_exists("slug",$this->table))
						$data['slug'] = sha1(time().json_encode($data));

					if (isset($data['tenant_id'])) 
						$record['tenant_id'] = $data['tenant_id'];

					$this->db->insert($this->table,$record);

					$cnt++;
				}
				
			}
		}
		return [
			"records_saved" => $cnt
		];
	}

	public function delete($input) {

		if (!$this->has_full_perms)
			return false;

		if (isset($input['id'])) {
			$id = $input['id'];
			$field = "id";
		}
		elseif (isset($input['slug'])) {
			$id = $input['slug'];
			$field = "slug";
		}
		else 
			return false;

		//make sure tenant_id is set
		if (isset($input['tenant_id']))
			$this->db->where("tenant_id",$input['tenant_id']);

		if ($this->soft_delete) {
			$this->db->set("deleted",1)
			->where($field,$id)
			->update($this->table);
		}
		else {
			$this->db->where($field,$id)
				->delete($this->table);
		}

		return $this->db->affected_rows();
		
	}

	/**
	*	CUSTOM FORM VALIDATION RULE
	**/
	public function unique($field_value,$table) {

	}

}