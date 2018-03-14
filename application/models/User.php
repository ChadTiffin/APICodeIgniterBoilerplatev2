<?php

class User extends Base_Model {
		
	public $table = "users";
	public $soft_delete = true;

	public $module_name = "Users";

	public $non_api_methods = ["createPasswordHash","sendEmail","generateUserToken","changePassword","userJobIds","delete"];

	public $hidden_fields = ["pw_hash","api_key","deleted"]; 

	public $default_orders = [
		["first_name","ASC"]
	];

	public $validation_rules = [];

	public $relations = [];

	public $psw_min_length = 7;

	//check user's password is valid
	public function authenticate($params)
	{
		//strip spaces from username/password in case of pasting
		$username = str_replace(" ", "", $params['email']);
		$password = str_replace(" ", "", $params['password']);

		$user = $this->db
			->where("email", $username)
			->get("users")
			->row_array();

		if ($user) {

			if (password_verify($password, $user['pw_hash'])) {

				unset($user['pw_hash']);

				$session_token = $this->generateUserToken($user['id'],8760,false); //1 year token expiry

				$user['session_token'] = $session_token['token'];
				$user['UserPermission'] = $this->db->get_where("tenants_users",["user_id" => $user['id']])->result_array();

				$this->load->model("Module");
				$user['Modules'] = $this->Module->get();

				return [
					'data' => $user
				];
			}
		}
		return false;
		
	}

	public function reset_request($params)
	{
		$email = $params['email'];

		//find user by email
		$user = $this->db->get_where($this->table, ['email' => $email])->row();

		if ($user) {
			$token = $this->generateUserToken($user->id,2);

			$this->sendEmail($user->email, 'emails/password_reset', $token, "Password Reset Request for ".APP_NAME);

		}
		return [
			"message" => "If we have your email on file we have sent you password instructions, which you should receive within a few minutes."
		];
	}

	public function reset_password($params)
	{

		$this->load->library('form_validation');

		$this->form_validation
			->set_rules('password','Password','required|min_length['.$this->psw_min_length.']')
			->set_error_delimiters('', '');

		if ($this->form_validation->run() == false) {

			$response = [
				'success' => false,
				'message' => validation_errors()
			];
		}
		elseif ($params['confirm'] != $params['password']) {
			$response = [
				'success' => false,
				'message' => "Your password doesn't match the confirmation field"
			];
		}
		else {
			//validate token
			$this->load->model("UserToken");
			$user_details = $this->UserToken->validateToken($params['token']);

			if ($user_details) {
				//check length

				$result = $this->changePassword($user_details->id,$params['password']);

				$response = [
					'success' => true,
					'message' => 'Password has been updated.'
				];
			}
			else {
				$response = [
					'success' => false,
					'message' => 'Invalid token. Please go initiate another password reset request.'
				];
			}
		}

		$this->response->json([],$response,$response['success']);
	}

	public function change_password($params)
	{
		$result = $this->changePassword($this->user_id,$params['password']);

		if ($result === true)
			$this->response->json([],[],true);
		else
			$this->response->json([],$result,false);
	}

	public function createPasswordHash($plaintext)
	{
		return password_hash($plaintext, PASSWORD_BCRYPT);
	}

	public function changePassword($user_id, $new_password)
	{
		//hash new password
		$hash = $this->createPasswordHash($new_password);
		
		// make sure password meets minimum characters
		if (strlen($new_password) >= $this->psw_min_length) {
			$result = $this->db->set([
							'pw_hash' => $hash
						])
						->where('id', $user_id)
						->update($this->table);
			return true;
		}
		else {
			return "Password must be at least ".$this->psw_min_length." characters.";
		}
	}

	public function sendEmail($email, $view, $view_data, $subject)
	{

		$this->load->library('email');

		$config['mailtype'] = 'html';

		$this->email->initialize($config);

		$from_email = "no-reply@getonteam.com";

		$this->load->library("parser");

		//add some commonly used values
		$view_data['app_name'] = APP_NAME;
		$view_data['front_end_domain'] = FRONT_END_DOMAIN;
		$view_data["base_url"] = base_url();

		return $this->email
			->from($from_email)
			->to($email)
			->subject($subject)
			->message($this->parser->parse($view,$view_data,true))
			->send();
	}

	public function generateUserToken($user_id, $expiry_hrs = 2,$single_use = true)
	{
		$login_token = hash('sha256', mt_rand(1000000,99999999).time());
		$token_expiry = date("Y-m-d H:i:s", time()+(60*60*$expiry_hrs));

		if ($single_use)
			$single_use = 1;
		else
			$single_use = 0;

		//save the token
		$this->db->insert("user_tokens",[
			'token' => $login_token,
			'issued' => date("Y-m-d H:i:s"),
			'expiry' => $token_expiry,
			'single_use' => $single_use,
			'user_id' => $user_id
		]);
		return [
			'token' => $login_token,
			'expiry' => $token_expiry
		];
	}

	public function save($data) {
		//if this is a new user, we need to create a bunch of things: slug, api key, ps_hash

		if (!$this->has_full_perms) {
			//no write permissions, check to see if this is the user's record
			if ($this->user_id == $data['id'])
				$this->has_full_perms = true;
		}

		$userCreate = false;
		if (!isset($data['id'])) {
			$userCreate = true;

			$this->validation_rules = [
				[
					'field' => "email",
					'label' => "Email",
					'rules' => "required|valid_email|is_unique[users.email]",
					"errors" => [
						'is_unique' => "That email is already taken by another user"
					]
				],
				[
					'field' => "first_name",
					'label' => "First Name",
					'rules' => "required"
				],
				[
					'field' => "last_name",
					'label' => "Last Name",
					'rules' => "required"
				]
			];
		}
		else {
			//email can never be changed after initial insert
			unset($data['email']);
			$this->validation_rules = [];
		}

		//perform save
		$result = parent::save($data);

		if (is_string($result) && $userCreate) {
			//save worked and it is an insert

			//$slug = sha1($data['first_name'].$data['last_name'].json_encode($result));

			$slug = $result;

			$new_ps = substr(md5(mt_rand(1000,999999)),1,8);
			$pw_hash = $this->createPasswordHash($new_ps);
			$api_key = sha1(mt_rand(1000,999999));

			$this->db
				->set("pw_hash",$pw_hash)
				->set("api_key",$api_key)
				->where("slug",$slug)
				->update("users");

			//email user with new credentials
			$view_data = [
				"username" => $data['email'],
				"password" => $new_ps
			];
			$this->sendEmail($data['email'], "emails/user_welcome", $view_data, "Welcome to ".APP_NAME);

		}

		return $result;

	}

}