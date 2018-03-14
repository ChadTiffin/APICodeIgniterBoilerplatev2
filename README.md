TEAM REST API - Introduction
====

The TEAM App is powered by an API loosely following REST principles. It is built using CodeIgniter 3 and MySQL. PHP 7+ is recommended.

Table of Contents
----
1. TEAM REST API - Introduction
2. Installation & Setup
3. API Usage
4. Working with Models


Architecture
----

All requests are run through a single `API` controller (`/application/controllers/Api.php`), using CodeIgniter's `_remap` function to provide automatic routing. Custom middleware libraries are used to enable CORS, and handle Authentication and Module Level Permissions, after which it is handed off to the Model to handle the request. The result from the model is run through the Response library to format the response JSON in a standard way.

A Base Model is utilized which all other models extend from. The Base model implements the API's standard `/get`, `/find`, `/save`, `/save-batch`, and `/delete` endpoints. Any specific record-level permissions are handled directly in the relevant model itself.

Installation & Setup
====

Normal CodeIgniter 3 set-up standards should be followed, such as setting the db credentials for the appropriate environment within the application/config/ directory.

### Setting Environment Constants

In the `application/config/config.php` file, `$config['base_url']` should be set to the the base url that's serving the API (ex. `https://api.yoursite.com`).

In the `application/config/constants.php` file, the `FRONT_END_DOMAIN` should be set to the domain name that serves the client application.

API Usage
====

The API is organized by Resource, which maps to CodeIgniter Models. Each Resource exposes a number of actions that can be performed on that resource. By default, a Resource exposes `/get`, `/find`, `/save`, `/delete`, and `/save-batch` actions, however in the CI model any of these actions can be turned off, and additional actions can be created specific to that Resource. In general only basic GET and POST HTTP verbs are used, with actions declared in the url being favoured to determine the operation to be performed on the resource instead. Any Read operation uses a GET method, any write or delete operation uses POST.

The API url format is as follows:

~~~
https://yourdomain.com/api/{Resource}/{Operation}
~~~

example:

~~~
https://yourdomain.com/api/Job/get
~~~
    
Returns JSON result of all records belonging to the Job.php CodeIgniter Model.

In most cases the JSON response will be formatted as follows:

    {
    	data: [{result set}],
    	success: true,
    	message: "A response message on failure" (optional)
    }

Authentication
----

Authentication is used via API keys. Each user is assigned an API key in the `users` table. That API key should be included in the header of most requests under the `X-Api-Key` header.

In the case where headers cannot be set, it is recommended that a user token is sent directly in the URL instead of the API key in order to protect the secrecy of the API key. User tokens can be set with an expiry and/or a single use flag, which will invalidate the token after it has been used on a single request. User tokens should be set under the `token` url variable.

Allowing Public Access to Specific Endpoints
----

Some endpoints may need to be public access (not requiring Authentication via API key or token), for example the `/api/User/authenticate` endpoint, which takes a username & password and returns an API key. In these cases there exists a `$unprotected_endpoints` property in the `API` Controller class (`/application/controllers/Api.php`) which is an array of endpoints. To set an enpoint for open access, simply add it as an element to that array like so:

~~~
public $unprotected_endpoints = [
	"/user/authenticate",
	"/user/reset-request",
	"/user/reset-password"
];
~~~

Standard Request Operations
----

Below is a list of the standard API operations that are exposed for each resource. In some cases these methods may be not available for particular resource. Other resources may have additional endpoints exposed, which will be defined in the CI models.

Enpoint 	| HTTP Request Type | Accepted Parameters	| Description
----		|----				|----					|----	
`/api/{Resource}/get` 		| GET 				| include, filters, orders, page | Retrieves a set of records from a resource. Ex. TimeLog/get
`/api/{Resource}/find/{slug or id}` | GET | include | Retrieves a single record based on declared record id or slug
`/api/{Resource}/save` | POST |  | Writes a record to the DB. If {id} field is included it will be an UPDATE, otherwise it will be an INSERT
`/api/{Resource}/save-batch` | POST | records | Writes a number of records to the DB. JSON Array of records to write should be sent in "records" form field.
`/api/{Resource}/delete`	| POST | id 	| Deletes a record based on the ID passed as a POST variable

/get Endpoint
----

The `/get` endpoint returns a record set from the defined resource. It accepts a number of parameters to help format and filter the response result accordingly, and to define the related models that should get returned.

### Including Child Models

Use the `include` GET query parameter. Available for `/get` and `/find` endpoints.

The `include` parameter allows you to declare related models that should be returned with the record set. It should be formatted as a JSON array. For example:

~~~
/api/Job/get?include=["User"]
~~~

The related child model will be appended to the parent record under a property named after the model name.

Would return the following JSON structure:

~~~
{
	data: [
		{
			id: 321,
			job_name: "Lighting Installation",
			... (other job fields),
			User: [
				{
					id: 2,
					first_name: "Chad",
					last_name: "Tiffin",
					... (other user fields)
				}
			]
		}
	],
	success: true
}
~~~

Notice the `User` property attached to each returned Job record, with an array of users that belong that a particular job.

### Filtering Result Sets

Use the `filters` GET query parameter. Available for `/get` endpoints.

The `filters` parameter should be a JSON array of arrays to define a set of filters to apply against the record set.

It accepts any of the following formats:

~~~
[{field}, {value}],
[{field} {operator}, {value}],
[{field}, {value}, 'and/or'],
[{field}, {value},'and/or','like']
~~~

For example:

~~~
filters: [
	['first_name','Tom'] //matches only records where first_name field equals Tom
]
~~~

or

~~~
filters: [
	['age >',16] //matches only records where age field is greater than (>) 16
]
~~~

or

~~~
filters: [
	//matches records where first_name matches Tom OR Dick
	['first_name','Tom']
	['first_name','Dick','or']
]
~~~

or

~~~
filters: [
	//matches records where first_name matches Tom AND last_name includes 'Mc'
	['first_name','Tom']
	['last_name','Mc','and','like'] //matches last_name to LIKE %Mc%
]
~~~

### Sorting Result Sets

Use the `orders` GET query parameter. Available for `/get` endpoints.

The `orders` parameter should be a JSON array of arrays to define the sort order of the result set.

For example:

~~~
orders: [
	//sorts by first_name Ascending first, then last_name Ascending second
	['first_name','ASC'],
	['last_name','ASC']
]
~~~

### Pagination

Use the `page` GET query parameter. Available for `/get` endpoints.

Returns the defined page for paginated result sets.

Some record sets will required pagination, so the `page` parameter allows you to define the desired page of records. It should be of type integer.

/find Endpoint
----

The `/find` endpoint returns a single resource record based on the defined id or slug.

Example:

`/api/User/find/12` returns the user whose ID matches `12`.

For security reasons, in order to obscure database IDs, most records also have a unique slug assigned to them, which is a non-sequential hash. Slugs can be used in place of IDs in most places:

`/api/User/find/d834sdgh0s' returns the user whose slug matches `d834sdgh0s`.

The `/find` endpoint accepts the `include` parameter just like the `/get` endpoint in order to attached related models to the returned record set.


/save Endpoint
----

The `/save` endpoint accepts a POST request of form-data to INSERT or UPDATE a model record. For UPDATES, the record `id` must be included as a field, otherwise a new record will be created.

### Updating Child Records through a Lookup Table

In most cases any child model records sent back as a form-data field will be ignored and not saved, however if a child model is detected through a lookup table, and the field value is an array of IDs, then that child model will be updated, overwriting any existing child records for that model and replacing it with the new provided list of IDs.

For example, if we have a many-to-many relationship of users and user roles, with a single user being able to be assigned multiple roles, we could update a user's assigned roles through the `User` model with a request like so to `/api/User/save`:

~~~
{
	id: 12,
	UserRole: [12,41,53]
}
~~~

Any records in the users_roles lookup table belonging to User 12 will be deleted, and replaced with records pointing to IDs 12, 41, and 53.


/save-batch Endpoint
----

The `/save-batch` endpoint will save a collection of records simultaneously in a single POST request. The collection of records must be sent in the `records` form-data field, as a JSON array. The collection will then be iterated and INSERTED or UPDATED accordingly.

**NOTE:** Batch saving does not produce detailed validation errors on validation failures, only a final count of the number of successful saves.


/delete Endpoint
----

The `/delete` endpoint takes a POST request with the `id` of the record to be deleted as a form-data field. Whether the record is truly deleted or just marked as deleted depends on whether that model's `$soft_delete` property is `true` or `false`.

No cascade deleting occurs.

Working with Models
====

There is a `Base_Model` class which implements the 5 standard endpoints (`/get`, `/find`, `/save`, etc), and regular models should extend this class in order to inherit the 5 standard endpoints automatically.

Conventions
----

In general model class names are named as the singular, Pascal case form of the record, and their corresponding table names are the snake case plural. For example, time logs would be handled by the `TimeLog` model, which maps to the `time_logs` table, however this is not an enforced constraint.

Model Properties
----

Property | Type | Description
---- | ---- | ----
`$table` | string | Defines the MySQL table that the model maps to
`$soft_delete` | Boolean | If true, any deleted records will be set to deleted=1 rather than actually deleted from db
`$non_api_methods` | Array | Any class method declared within this array will not be exposed as an API endpoint
`$page_limit` | Integer | Sets the number of records per page
`$validation_rules` | Array | Validation rules for the Model. See CodeIgniter Form Validation for formatting.
`$hidden_fields` | Array | Any fields declared here will be stripped from the result set before being sent to the client
`$module_name` | String | Allows you to group this model in with other models to form a Permissions Module
`$default_orders` | Array | Results will be automatically sorted by the rules defined here
`$internal_model_only` | Boolean | If set to the true, the entire model will not be available as an API resource
`$relations` | Array | Defines what other Models are related to this model. See below for more information.
`$user_id` | Integer | This will be set to the user `id` that has been identified by the the attached API key. Only the parent model (the resource listed in the API call) will have the `user_id` property set. Any additional models that need to be loaded will need to have the `user_id` property set manually. Ex. `$this->job->user_id = $this->user_id;`
`$has_full_perms` | Boolean | If true, any record level permissions don't need to be checked

Model Relations
----

There is a robust means of declaring relations between different models through the `$relations` model property, which should contain an array of model declarations.

### Belongs to

To declare a "Belongs To" relationship, where a foreign key is set on the model's own table, the relation declaration should be as follows:

~~~
[
	'model' => (string) {childModelName},
	'key' => (string) {foreign_key_name}
]
~~~

For example, if we had a `Job` model, and each job belonged to a single `Customer`, the relation would be declared as follows in the `Job` model:

~~~
public $relations = [
	[
		'model' => 'Customer',
		'key' => 'customer_id'
	]
]
~~~

Now when a job record is queried, with the `Customer` model included, it would produce a result something like:

~~~
{
	id: 1,
	customer_id: 3,
	job_name: "Lighting Installation",
	date_landed: 2018-03-01,
	Customer: {
		id: 3,
		customer_name: "Hamilton Tigercats",
		phone_number: "519-232-1112"
	}
}
~~~

### One to Many

The opposite of a "Belongs To" relationship would be a "One-to-Many" relationship. Using the same example of Customers and Jobs, a customer would have many jobs, so in the `Customer` model, we would declare it like so:

~~~
[
	'model' => 'Job',
	'key' => 'customer_id',
	'hasMany' => true
]
~~~

Note the `hasMany` property which is set to `true`. This notifies the model to look for the 'customer_id' key on the child table (jobs) rather than its own table (customers), and return an array of result objects, rather than just a single object.

### Many to Many

For many-to-many relationships, that are accessed through a lookup table, we must declare the name of the lookup table, as well as the parent model's key being used on the lookup table:

~~~
[
	'model' => (string) {childModelName},
	'key' => (string) {foreign_key_name},
	'hasMany' => true,
	'lookupTable' => (string) {name of lookup table},
	'lookupTableSelfKey' => (string) {name of foreign key used to reference parent}
]
~~~

For example, if we had an `Author` model, and a `Book` model, a book might have multiple authors, and an author might have multiple books, so we might have tables that looked like so:

authors |
---- |
id
author_name |
dob |

books |
---- |
id |
book_title |
date_published |

authors_books |
---- |
id
author_id
book_id

So in the `Author` model, we would declare the relation like so:

~~~
public $relations = [
	'model' => 'Book',
	'key' => 'book_id',
	'hasMany' => true,
	'lookupTable' => 'authors_books',
	'lookupTableSelfKey' => 'author_id'
]
~~~

### Renaming Result Properties

By default, the model name will be used as the property that the child records will be appended to in the main result set. Often a model will have a different relationship to the same model, such as a model which tracks who **created** the record and who **updated** the record last. These are both relationships to the same model (the `User` model), so they can't BOTH be included under the 'User' property.

To get around this we have an additional property we can set in model relations: `explicit_key`. This allows us to set the property to whatever we want that the child records will be appended under:

~~~
public $relations = [
	[
		'model' => 'User',
		'key' => 'updated_by',
		'explicit_key' => 'UpdatedBy'
	],
	[
		'model' => 'User',
		'key' => 'created_by',
		'explicit_key' => 'CreatedBy'
	]
]
~~~

Would produce:

~~~
{
	id: 1,
	job_name: "Lighting Installation",
	date_landed: 2018-03-01,
	updated_by: 3,
	created_by: 2,
	UpdatedBy: {
		id: 3,
		first_name: "Chad",
		last_name: "Tiffin"
	},
	CreatedBy: {
		id: 2,
		first_name: "Chuck",
		last_name: "Norris"
	}
}
~~~

