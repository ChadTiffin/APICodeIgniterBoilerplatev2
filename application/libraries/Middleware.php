<?php 

class Middleware {
	
	/**
	* check for validity of API key, or short-lived user token
	* if invalid, return false
	**/
	public function checkApiKey() {

		$CI =& get_instance(); //get instance of CI because we're not extending a CI class

		$request_headers = $CI->input->request_headers();

		$CI->load->model("user");
		$CI->user->has_full_perms = true;

		if (isset($request_headers['X-Api-Key'])) {
			$api_key = $request_headers['X-Api-Key'];

			$user = $CI->db->get_where("users",["api_key" => $api_key])->row_array();
		}
		else {

			//check query string for token
			$token = $CI->input->get("token");

			$CI->load->model("userToken");

			$user = $CI->db->select("users.*")
				->from("users")
				->join("user_tokens","user_tokens.user_id = users.id")
				->where("user_tokens.token",$token)
				->get()->row_array();

		}

		if (isset($user['id'])) 
			return $user;
		else 
			return false;
			
	}

	/**
	*	CHECK PERMISSIONS ON A REQUEST
	*	@return true: has permission, @return false: does not have permission
	**/
	public function checkPermissions($user, $resource, $segments) {
		// Do what you will with this function, and then call it where necessary (usually in API controller)

	}

	public function cors() {

		$origin = "";
		if (isset($_SERVER['HTTP_ORIGIN']))
			$origin = $_SERVER['HTTP_ORIGIN'];

		header('Access-Control-Allow-Origin: '.$origin);
		header('Access-Control-Allow-Credentials: true');
		header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding, X-Api-Key");
		header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
		header("Access-Control-Max-Age: 2400");

		if ( "OPTIONS" === $_SERVER['REQUEST_METHOD'] ) {
			die();
		}

	}

}