<?php 

class Response {
	//RETURNS JSON RESPONSE
	/*EXAMPLE:
	{
		"success": true (default),
		"message": "",
		"example_meta_data": true,
		"time": YYYY-MM-DD XX:XX:XX
		"data" : {
			...
		}
	}
	*/
	public function json($data = [], $meta = [], $success = true, $code = 200){

		if (gettype($meta) == "string") 
			$response['message'] = $meta; //if $meta is string, pass it directly in as a message
		elseif (gettype($meta) == "boolean")
			$response['success'] = $meta;
		else 
			$response = $meta;

		$response['success'] = $success;
		$response['time'] = date("Y-m-d H:i:s");

		if ($code != 200)
			$response['code'] = $code;

		if (!isset($meta['data']))
			$response['data'] = $data; //set data property with data if data isn't empty

		$CI =& get_instance();

		//$response = $CI->middleware->stripIds($response);

		$CI->output
			->set_content_type("application/json")
			->set_output(json_encode($response))
			->_display();
			
		die();

	}
}