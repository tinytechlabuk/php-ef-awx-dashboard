<?php
// **
// USED TO DEFINE PLUGIN INFORMATION & CLASS
// **

// PLUGIN INFORMATION - This should match what is in plugin.json
$GLOBALS['plugins']['awx'] = [ // Plugin Name
	'name' => 'awx', // Plugin Name
	'author' => 'TinyTechLabUK', // Who wrote the plugin
	'category' => 'Ansible AWX', // One to Two Word Description
	'link' => 'https://github.com/tinytechlabuk/php-ef-awx-dashboard', // Link to plugin info
	'version' => '1.0.0', // SemVer of plugin
	'image' => 'logo.png', // 1:1 non transparent image for plugin
	'settings' => true, // does plugin need a settings modal?
	'api' => '/api/plugin/awx/settings', // api route for settings page, or null if no settings page
];

class awxPlugin extends ib {
	public function __construct() {
		parent::__construct();
	}

	public function _pluginGetSettings() {
		$Ansible = new awxPluginAnsible();
		$AnsibleLabels = $Ansible->GetAnsibleLabels() ?? null;
		$AnsibleLabelsKeyValuePairs = [];
		$AnsibleLabelsKeyValuePairs[] = [
			"name" => "None",
			"value" => ""
		];
		if ($AnsibleLabels) {
			$AnsibleLabelsKeyValuePairs = array_merge($AnsibleLabelsKeyValuePairs,array_map(function($item) {
				return [
					"name" => $item['name'],
					"value" => $item['name']
				];
			}, $AnsibleLabels));
		}
		return array(
			'Plugin Settings' => array(
				$this->settingsOption('auth', 'ACL-READ', ['label' => 'awx Read ACL']),
				$this->settingsOption('auth', 'ACL-WRITE', ['label' => 'awx Write ACL']),
				$this->settingsOption('auth', 'ACL-ADMIN', ['label' => 'awx Admin ACL']),
				$this->settingsOption('auth', 'ACL-JOB', ['label' => 'Grants access to use Ansible Integration'])
			),
			'Ansible Settings' => array(
				$this->settingsOption('url', 'Ansible-URL', ['label' => 'Ansible AWX URL']),
				$this->settingsOption('token', 'Ansible-Token', ['label' => 'Ansible AWX Token']),
				$this->settingsOption('select-multiple', 'Ansible-Tag', ['label' => 'The tag to use when filtering available jobs', 'options' => $AnsibleLabelsKeyValuePairs]),
				$this->settingsOption('blank'),
				$this->settingsOption('checkbox','Ansible-JobByLabel', ['label' => 'Organise Jobs by Label'])
			),
		);
	}
}

class awxPluginAnsible extends awxPlugin {
	public function __construct() {
	  parent::__construct();
	}

	public function QueryAnsible($Method, $Uri, $Data = "") {
		$awxConfig = $this->config->get("Plugins","awx");
		$AnsibleApiKey = $awxConfig["Ansible-Token"] ?? null;
		$AnsibleUrl = $awxConfig['Ansible-URL'] ?? null;

		if (!$AnsibleApiKey) {
				$this->api->setAPIResponse('Error','Ansible API Key Missing');
				return false;
		}

		if (!$AnsibleUrl) {
				$this->api->setAPIResponse('Error','Ansible URL Missing');
				return false;
		}

		$AnsibleHeaders = array(
		 'Authorization' => "Bearer $AnsibleApiKey",
		 'Content-Type' => "application/json"
		);

		if (strpos($Uri,$AnsibleUrl."/api/") === FALSE) {
		  $Url = $AnsibleUrl."/api/v2/".$Uri;
		} else {
		  $Url = $Uri;
		}

		if ($Method == "get") {
			$Result = $this->api->query->$Method($Url,$AnsibleHeaders,null,true);
		} else {
			$Result = $this->api->query->$Method($Url,$Data,$AnsibleHeaders,null,true);
		}
		if (isset($Result->status_code)) {
		  if ($Result->status_code >= 400 && $Result->status_code < 600) {
			switch($Result->status_code) {
			  case 401:
				$this->api->setAPIResponse('Error','Ansible API Key incorrect or expired');
				$this->logging->writeLog("Ansible","Error. Ansible API Key incorrect or expired.","error");
				break;
			  case 404:
				$this->api->setAPIResponse('Error','HTTP 404 Not Found');
				break;
			  default:
				$this->api->setAPIResponse('Error','HTTP '.$Result->status_code);
				break;
			}
		  }
		}
		if ($Result->body) {
		  $Output = json_decode($Result->body,true);
		  if (isset($Output['results'])) {
				return $Output['results'];
		  } else {
				return $Output;
		  }
		} else {
				$this->api->setAPIResponse('Warning','No results returned from the API');
		}
	}
	
	public function GetAnsibleJobTemplate($id = null,$label = null) {
	  $Filters = array();
	  $AnsibleTags = $this->config->get("Plugins","awx")['Ansible-Tag'] ?? null;
	  if ($label) {
		array_push($Filters, "labels__name__in=$label");
	  } elseif ($AnsibleTags) {
		array_push($Filters, "labels__name__in=".implode(',',$AnsibleTags));
	  }
	  if ($Filters) {
		$filter = combineFilters($Filters);
	  }
	  if ($id) {
		$Result = $this->QueryAnsible("get", "job_templates/".$id."/");
	  } else if (isset($filter)) {
		$Result = $this->QueryAnsible("get", "job_templates/?".$filter);
	  } else {
		$Result = $this->QueryAnsible("get", "job_templates");
	  }
	  if ($Result) {
		$this->api->setAPIResponseData($Result);
		return $Result;
	  }
	}
	
	public function GetAnsibleJobs($id = null) {
	  $Result = $this->QueryAnsible("get", "jobs");
	  if ($Result) {
		$this->api->setAPIResponseData($Result);
		return $Result;
	  } else {
		$this->api->setAPIResponse('Warning','No results returned from the API');
	  }
	}
	
	public function SubmitAnsibleJob($id,$data) {
	  $Result = $this->QueryAnsible("post", "job_templates/".$id."/launch/", $data);
	  if ($Result) {
		$this->api->setAPIResponseData($Result);
		return $Result;
	  } else {
		$this->api->setAPIResponse('Warning','No results returned from the API');
	  }
	}

	public function GetAnsibleLabels() {
		$Result = $this->QueryAnsible("get", "labels/?order_by=name");
		if ($Result) {
			$this->api->setAPIResponseData($Result);
			return $Result;
		} else {
			$this->api->setAPIResponse('Warning','No results returned from the API');
		}
	}
}