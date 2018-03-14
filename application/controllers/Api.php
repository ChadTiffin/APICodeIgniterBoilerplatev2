<?php

class Api extends CI_Controller {

	public $unprotected_endpoints = [
		"/user/authenticate",
		"/user/reset-request",
		"/user/reset-password"
	];

	public function _remap($resource,$segments)
	{
		$this->middleware->cors();

		if (!$segments) {
			$this->response->json([],"Bad request. Requests to API endpoints must include an action like /get, /find, /save, or /delete after the resource.",false,400);
		}

		$action = str_replace("-", "_", $segments[0]);

		//////////////////////////////////////////////////////////
		//	VALID ROUTE CHECKING
		//	make sure resource (model) and method both exist, otherwise throw a 404 response
		//////////////////////////////////////////////////////////
		
		try {
			$this->load->model($resource);

			if (!method_exists($resource, $action)
				|| in_array($action,$this->$resource->non_api_methods)
				|| $this->$resource->internal_model_only) 
				//method doesn't exist in the model or is listed as a non-api model or method
				$this->response->json([],"404 Route not found",false,404);
			
		}
		catch (Exception $ex) {

			$this->response->json([],"404 Resource not found",false,404);

		}
		
		////////////////////////////////
		//	RETRIEVE INPUT
		////////////////////////////////

		//get request type and retrieve proper request parameters
		if ($this->input->method() == "post")
			$input = $this->input->post();
		elseif ($this->input->method() == "get")
			$input = $this->input->get();

		//////////////////////////////////////////////////////////
		//	AUTHENTICATION & PERMISSIONS
		//////////////////////////////////////////////////////////

		$endpoint = "/".$resource."/".$segments[0]; //don't use action because that may have - converted to _

		$user = false;
		if (!in_array($endpoint, $this->unprotected_endpoints)) {
			$user = $this->middleware->checkApiKey();

			if (!$user)
				$this->response->json([],"Token or Api key not valid",false,403);
		}
		
		/////////////////
		//	ROUTING
		/////////////////
		switch ($action) {
			case "find":

				if ($this->input->method() != 'get')
					$this->response->json([],"Invalid request method",false,405);

				$id = $segments[1];
				$result = $this->$resource->find($id,$input);

				if (!$result)
					$this->response->json([],"Record not found",false);

				break;
			case "get":

				if ($this->input->method() != 'get')
					$this->response->json([],"Invalid request method",false,405);

				$result = $this->$resource->get($input);

				if (!$result)
					$this->response->json([],"No matches found",true);
				break;
			case "save":

				if ($this->input->method() != 'post')
					$this->response->json([],"Invalid request method",false,405);

				$result = $this->$resource->save($input);

				if (!is_array($result)) 
					//success
					$this->response->json([],["record_id" => $result],true);
				
				else 
					$this->response->json([],["errors" => $result],false);
				
				break;
			case "save-batch":

				if ($this->input->method() != 'post')
					$this->response->json([],"Invalid request method",false,405);

				$result = $this->$resource->saveBatch($input);
				$this->response->json([],["inserted_records" => $result],true);

				break;
			case "delete":

				if ($this->input->method() != 'post')
					$this->response->json([],"Invalid request method",false,405);

				$result = $this->$resource->delete($input);

				$this->response->json([],["deleted_records" => $result],true);
				break;

			default:

				$result = $this->$resource->$action($input);

				if (!$result)
					$this->response->json([],"Request was unsuccessful",false);

				$this->response->json([],$result,true);
		}

		$this->response->json($result,[],true);
		
	}
}
