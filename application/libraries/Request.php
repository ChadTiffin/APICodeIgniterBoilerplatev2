<?php 

class Request {

	public function http_request($url, $headers=[],$type = "get", $fields = []) {
		$curl = curl_init($url);

		if (strtolower($type) == "post") {
			$type = 1;

			curl_setopt($curl,CURLOPT_POST,1);
			curl_setopt($curl,CURLOPT_POSTFIELDS,$fields);
		}

		curl_setopt_array($curl, array(
		    CURLOPT_RETURNTRANSFER => 1,
		    CURLOPT_FOLLOWLOCATION => true,
		    CURLOPT_HTTPHEADER => $headers
		));

		$response = curl_exec($curl);
		curl_close($curl);

		return $response;
	}

	//shorthand function
	public function get($url, $fields = [], $headers=[])
	{
		return $this->http_request($url,$headers,"get", $fields);
	}

	public function post($url, $fields = [], $headers=[])
	{
		return $this->http_request($url,$headers,"post", $fields);
	}
}