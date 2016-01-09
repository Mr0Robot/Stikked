<?php
/**
 * Class and Function List:
 * Function list:
 * - __construct()
 * - countPastes()
 * - countReplies()
 * - createPaste()
 * - _get_url()
 * - _shorten_url()
 * - checkPaste()
 * - getPaste()
 * - calculate_hits()
 * - getReplies()
 * - getLists()
 * - getTrends()
 * - getSpamLists()
 * - cron()
 * - delete_paste()
 * - random_paste()
 * - _format_diff()
 * - _strip_bad_multibyte_chars()
 * Classes list:
 * - Pastes extends CI_Model
 */

class Pastes extends CI_Model
{
	
	function __construct() 
	{
		parent::__construct();
	}
	
	function countPastes($ip_address = false) 
	{
		$this->db->where('private', 0);
		
		if ($ip_address) 
		{
			$this->db->where('ip_address', $ip_address);
		}
		$query = $this->db->get('pastes');
		return $query->num_rows();
	}
	
	function countReplies($pid) 
	{
		$this->db->where('replyto', $pid);
		$query = $this->db->get('pastes');
		return $query->num_rows();
	}
	
	function createPaste() 
	{
		$data['created'] = time();

		//this is SO evil… saving the «raw» data with htmlspecialchars :-( (but I have to leave this, because of backwards-compatibility)
		$data['raw'] = htmlspecialchars($this->_strip_bad_multibyte_chars($this->input->post('codeBox')));
		$data['lang'] = htmlspecialchars($this->input->post('lang'));
		$data['replyto'] = $this->input->post('reply');
		
		if ($this->input->post('name')) 
		{
			$data['name'] = htmlspecialchars($this->input->post('name'));
		}
		else
		{
			$data['name'] = $this->config->item('unknown_poster');
			
			if ($data['name'] == 'random') 
			{
				$nouns = $this->config->item('nouns');
				$adjectives = $this->config->item('adjectives');
				$data['name'] = $adjectives[array_rand($adjectives) ] . " " . $nouns[array_rand($nouns) ];
			}
		}
		
		if ($this->input->post('title')) 
		{
			$data['title'] = htmlspecialchars($this->input->post('title'));
		}
		else
		{
			$data['title'] = $this->config->item('unknown_title');
		}
		$data['private'] = $this->input->post('private');
		do 
		{
			$data['pid'] = substr(md5(md5(mt_rand(0, 1000000) . mktime())) , rand(0, 24) , 8);
			$this->db->select('id');
			$this->db->where('pid', $data['pid']);
			$query = $this->db->get('pastes');
			
			if ($query->num_rows > 0 or $data['pid'] == 'download') 
			{
				$n = 0;
				break;
			}
			else
			{
				$n = 1;
				break;
			}
		}
		while ($n == 0);
		$burn = false;
		
		if ($this->input->post('expire') == '0') 
		{
			$data['expire'] = 0;
		}
		else 
		if ($this->input->post('expire') == 'burn') 
		{
			$burn = true;
			$data['toexpire'] = 1;
			$data['expire'] = 0;
			$data['private'] = 1;
		}
		else
		{
			$format = 'Y-m-d H:i:s';
			$data['toexpire'] = 1;
			$data['expire'] = mktime() + (60 * $this->input->post('expire'));
		}
		
		if ($this->input->post('snipurl') == false) 
		{
			$data['snipurl'] = false;
		}
		else
		{
			$url = $this->_get_url($data['pid']);
			$shorturl = $this->_shorten_url($url);
			$data['snipurl'] = $shorturl;
		}
		$data['ip_address'] = $this->input->ip_address();
		if (isset($_SESSION['isloggedin']) && $_SESSION['isloggedin'] == true) {
			$data['username'] = $_SESSION['username'];
		}
		$this->db->insert('pastes', $data);
		
		if ($burn) 
		{
			echo 'copy this URL, it will become invalid on visit: ' . site_url('view/' . $data['pid']);
			exit;
		}
		else
		{
			return 'view/' . $data['pid'];
		}
	}
	private 
	function _get_url($pid) 
	{
		$override_url = $this->config->item('displayurl_override');
		return ($override_url ? str_replace('$id', $pid, $override_url) : site_url('view/' . $pid));
	}
	private 
	function _shorten_url($url) 
	{
		$config_yourls_url = $this->config->item('yourls_url');
		
		if ($config_yourls_url) 
		{

			//use yourls
			$config_yourls_url = $this->config->item('yourls_url');
			$config_yourls_signature = $this->config->item('yourls_signature');
			$timestamp = time();
			$signature = md5($timestamp . $config_yourls_signature);

			// Init the CURL session
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $config_yourls_url . 'yourls-api.php');
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, array(
				'url' => $url,
				'format' => 'simple',
				'action' => 'shorturl',
				'signature' => $signature,
				'timestamp' => $timestamp,
			));
			$resp = curl_exec($ch);
			curl_close($ch);
			$shorturl = (empty($resp) ? false : $resp);
		}
		else
		{

			//use gdgw
			$url = urlencode($url);
			$config_gwgd_url = $this->config->item('gwgd_url');
			$gwgd_url = ($config_gwgd_url ? $config_gwgd_url : 'http://gw.gd/');
			$target = $gwgd_url . 'api.php?long=' . $url;

			// Init the CURL session
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $target);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_ENCODING, 'identity');
			$resp = curl_exec($ch);
			curl_close($ch);
			$shorturl = (empty($resp) ? false : $resp);
		}
		return $shorturl;
	}
	
	function checkPaste($seg = 2) 
	{
		
		if ($this->uri->segment($seg) == "") 
		{
			return false;
		}
		else
		{
			$this->db->where('pid', $this->uri->segment($seg));
			$query = $this->db->get('pastes');
			
			if ($query->num_rows() > 0) 
			{
				return true;
			}
			else
			{
				return false;
			}
		}
	}
	
	function getPaste($seg = 2, $replies = false, $diff = false) 
	{
		
		if ($this->uri->segment($seg) == '') 
		{
			redirect('');
		}
		else
		{
			$pid = $this->uri->segment($seg);
			$data['script'] = 'jquery.js';
		}
		$this->load->library('process');
		$this->db->where('pid', $pid);
		$query = $this->db->get('pastes');
		foreach ($query->result_array() as $row) 
		{
			$data['title'] = $row['title'];
			$data['pid'] = $row['pid'];
			$data['name'] = $row['name'];
			$data['lang_code'] = $row['lang'];
			$data['lang'] = $this->languages->code_to_description($row['lang']);
			//$data['paste'] = $this->process->syntax(htmlspecialchars_decode($row['raw']) , $row['lang']);
			$data['language'] = $row['lang'];
			$data['created'] = $row['created'];
			$data['private'] = $row['private'];
			$data['expire'] = $row['expire'];
			$data['toexpire'] = $row['toexpire'];
			$data['url'] = $this->_get_url($row['pid']);
			$data['raw'] = $row['raw'];
			$data['hits'] = $row['hits'];
			$data['hits_updated'] = $row['hits_updated'];
			$data['snipurl'] = $row['snipurl'];
			$inreply = $row['replyto'];
		}
		
		if ($inreply) 
		{
			$this->db->select('name, title');
			$this->db->where('pid', $inreply);
			$query = $this->db->get('pastes');
			
			if ($query->num_rows() > 0) 
			{
				foreach ($query->result_array() as $row) 
				{
					$data['inreply']['title'] = $row['title'];
					$data['inreply']['name'] = $row['name'];
					$data['inreply']['url'] = site_url('view/' . $inreply);
				}
			}
			else
			{
				$data['inreply'] = false;
			}
			
			if ($diff) 
			{
				$this->db->select('raw');
				$this->db->where('pid', $inreply);
				$query = $this->db->get('pastes');
				
				if ($query->num_rows() > 0) 
				{
					foreach ($query->result_array() as $row) 
					{

						//diff
						//yes, I'm aware, two times htmlspecialchars_decode(). Needs to be, since it's saved that way in the DB from the original stikked author ages ago ;)

						include_once (APPPATH . '/libraries/finediff.php');
						$from_text = htmlspecialchars_decode(utf8_decode($row['raw']));
						$to_text = htmlspecialchars_decode(utf8_decode($data['raw']));
						$opcodes = FineDiff::getDiffOpcodes($from_text, $to_text, FineDiff::$wordGranularity);
						$to_text = FineDiff::renderToTextFromOpcodes($from_text, $opcodes);
						$data['paste'] = htmlspecialchars_decode($this->_format_diff(nl2br(FineDiff::renderDiffToHTMLFromOpcodes($from_text, $opcodes))));
					}
				}
				else
				{
					$data['inreply'] = false;
				}
			}
		}
		
		if ($replies) 
		{
			$amount = $this->config->item('per_page');
			$page = ($this->uri->segment(3) ? $this->uri->segment(3) : 0);
			$this->db->select('title, name, created, pid, lang');
			$this->db->where('replyto', $data['pid']);
			$this->db->order_by('id', 'desc');
			$this->db->limit($amount);
			$query = $this->db->get('pastes', $amount, $page);
			
			if ($query->num_rows() > 0) 
			{
				$n = 0;
				foreach ($query->result_array() as $row) 
				{
					$data['replies'][$n]['title'] = $row['title'];
					$data['replies'][$n]['name'] = $row['name'];
					$data['replies'][$n]['lang'] = $row['lang'];
					$data['replies'][$n]['created'] = $row['created'];
					$data['replies'][$n]['pid'] = $row['pid'];
					$n++;
				}
				$config['base_url'] = site_url('view/' . $data['pid']);
				$config['total_rows'] = $this->countReplies($data['pid']);
				$config['per_page'] = $amount;
				$config['num_links'] = 9;
				$config['full_tag_open'] = '<div class="pages">';
				$config['full_tag_close'] = '</div>';
				$config['uri_segment'] = 3;
				$this->load->library('pagination');
				$this->pagination->initialize($config);
				$data['pages'] = $this->pagination->create_links();
			}
			else
			{
				$replies = false;
			}
		}

		/*
		 * Hits
		 * First check if record already exists.  If it does, do not insert.
		 * INSERT IGNORE INTO does not work for postgres.
		*/
		$this->db->select('count(paste_id) as count');
		$this->db->where('paste_id', $pid);
		$this->db->where('ip_address', $this->input->ip_address());
		$query = $this->db->get('trending');
		$hits_count = $query->result_array();
		$hits_count = $hits_count[0]['count'];
		
		if ($hits_count == 0) 
		{
			$this->db->insert('trending', array(
				'paste_id' => $pid,
				'ip_address' => $this->input->ip_address() ,
				'created' => mktime() ,
			));
		}

		//update hits counter every minute
		
		if (mktime() > (60 + $data['hits_updated'])) 
		{
			$this->calculate_hits($pid, $data['hits']);
		}

		//burn if necessary
		
		if ($data['expire'] == 0 and $data['toexpire'] == 1) 
		{
			$this->delete_paste($data['pid']);
		}
		return $data;
	}
	
	function calculate_hits($pid, $current_hits) 
	{
		$this->db->select('count(paste_id) as count');
		$this->db->where('paste_id', $pid);
		$query = $this->db->get('trending');
		$hits_count = $query->result_array();
		$hits_count = $hits_count[0]['count'];
		
		if ($hits_count != $current_hits) 
		{

			//update
			$this->db->where('pid', $pid);
			$this->db->update('pastes', array(
				'hits' => $hits_count,
				'hits_updated' => mktime() ,
			));
		}
	}
	
	function getReplies($seg = 3) 
	{
		$amount = $this->config->item('per_page');
		
		if ($this->uri->segment($seg) == '') 
		{
			redirect('');
		}
		else
		{
			$pid = $this->uri->segment($seg);
		}
		$this->db->select('title, name, created, pid, raw, lang');
		$this->db->where('replyto', $pid);
		$this->db->order_by('id', 'desc');
		$this->db->limit($amount);
		$query = $this->db->get('pastes', $amount);
		
		if ($query->num_rows() > 0) 
		{
			$n = 0;
			foreach ($query->result_array() as $row) 
			{
				$data['replies'][$n]['title'] = $row['title'];
				$data['replies'][$n]['name'] = $row['name'];
				$data['replies'][$n]['lang'] = $row['lang'];
				$data['replies'][$n]['created'] = $row['created'];
				$data['replies'][$n]['pid'] = $row['pid'];
				
				if ($this->uri->segment(2) == 'rss') 
				{
					//$data['replies'][$n]['paste'] = $this->process->syntax(htmlspecialchars_decode($row['raw']) , $row['lang']);
					$data['replies'][$n]['raw'] = $row['raw'];
				}
				$n++;
			}
		}
		return $data;
	}
	
	function getLists($root = 'lists/', $seg = 2) 
	{
		$this->load->library('pagination');
		$this->load->library('process');
		$amount = $this->config->item('per_page');
		$page = ($this->uri->segment($seg) ? $this->uri->segment($seg) : 0);
		$search = $this->input->get('search');
		
		if ($search) 
		{
			$search = '%' . $search . '%';

			// count total results
			$sql = "SELECT id FROM pastes WHERE private = 0 AND (title LIKE ? OR raw LIKE ?)";
			$query = $this->db->query($sql, array(
				$search,
				$search,
			));
			$total_rows = $query->num_rows();

			// query
			
			if ($this->db->dbdriver == "postgre") 
			{
				$sql = "SELECT id, title, name, created, pid, lang, raw FROM pastes WHERE private = 0 AND (title LIKE ? OR raw LIKE ?) ORDER BY created DESC LIMIT $amount OFFSET $page";
			}
			else 
			if ($root == 'api/recent') 
			{
				$sql = "SELECT id, title, name, created, pid, lang, raw FROM pastes WHERE private = 0 AND (title LIKE ? OR raw LIKE ?) ORDER BY created DESC LIMIT 0,15";
			}
			else
			{
				$sql = "SELECT id, title, name, created, pid, lang, raw FROM pastes WHERE private = 0 AND (title LIKE ? OR raw LIKE ?) ORDER BY created DESC LIMIT $page,$amount";
			}
			$query = $this->db->query($sql, array(
				$search,
				$search,
			));
		}
		else
		{

			// count total results
			$sql = "SELECT id FROM pastes WHERE private = 0";
			if (isset($_SESSION['isloggedin']) && $_SESSION['isloggedin'] == true) {
					$sql = "SELECT id FROM pastes WHERE username = '".$_SESSION['username']."' ORDER BY created DESC LIMIT $page, $amount";
			}
			$query = $this->db->query($sql);
			$total_rows = $query->num_rows();

			// query
			
			if ($this->db->dbdriver == "postgre") 
			{
				//$sql = "SELECT id, title, name, created, pid, lang, raw FROM pastes WHERE private = 0 ORDER BY created DESC LIMIT $amount OFFSET $page";
			}
			else
			{
				$sql = "SELECT * FROM pastes WHERE private = 0 ORDER BY created DESC LIMIT $page,$amount";
				if (isset($_SESSION['isloggedin']) && $_SESSION['isloggedin'] == true) {
					$sql = "SELECT * FROM pastes WHERE username = '".$_SESSION['username']."' ORDER BY created DESC LIMIT $page, $amount";
				}
			}
			$query = $this->db->query($sql);
		}
		
		if ($query->num_rows() > 0) 
		{
			$n = 0;
			foreach ($query->result_array() as $row) 
			{
				$data['pastes'][$n]['id'] = $row['id'];
				$data['pastes'][$n]['title'] = $row['title'];
				$data['pastes'][$n]['name'] = $row['name'];
				$data['pastes'][$n]['created'] = $row['created'];
				$data['pastes'][$n]['lang'] = $this->languages->code_to_description($row['lang']);
				$data['pastes'][$n]['pid'] = $row['pid'];
				
				if ($this->uri->segment(2) == 'rss') 
				{
					$data['pastes'][$n]['paste'] = $this->process->syntax(htmlspecialchars_decode($row['raw']) , $row['lang']);
				}
				$data['pastes'][$n]['raw'] = $row['raw'];
				$n++;
			}
		}
		$config['base_url'] = site_url($root);
		$config['total_rows'] = $total_rows;
		$config['per_page'] = $amount;
		$config['num_links'] = 9;
		$config['full_tag_open'] = '<div class="pages">';
		$config['full_tag_close'] = '</div>';
		$config['uri_segment'] = $seg;
		$searchparams = ($this->input->get('search') ? '?search=' . $this->input->get('search') : '');
		$config['first_url'] = '0' . $searchparams;
		$config['suffix'] = $searchparams;
		$this->pagination->initialize($config);
		$data['pages'] = $this->pagination->create_links();
		return $data;
	}
	
	function getTrends($root = 'trends/', $seg = 2) 
	{
		$this->load->library('pagination');
		$amount = $this->config->item('per_page');
		$page = ($this->uri->segment(2) ? $this->uri->segment(2) : 0);
		$search = $this->input->get('search');
		
		if ($search) 
		{
			$search = '%' . $search . '%';

			// count total results
			$sql = "SELECT id FROM pastes WHERE private = 0 AND (title LIKE ? OR raw LIKE ?)";
			$query = $this->db->query($sql, array(
				$search,
				$search,
			));
			$total_rows = $query->num_rows();

			// query
			
			if ($this->db->dbdriver == "postgre") 
			{
				$sql = "SELECT id, title, name, created, pid, lang, raw, hits FROM pastes WHERE private = 0 AND (title LIKE ? OR raw LIKE ?) ORDER BY hits DESC, created DESC LIMIT $amount OFFSET $page";
			}
			else 
			if ($root == "api/trending") 
			{
				$sql = "SELECT id, title, name, created, pid, lang, raw, hits FROM pastes WHERE private = 0 AND (title LIKE ? OR raw LIKE ?) ORDER BY hits DESC, created DESC LIMIT 0,15";
			}
			else
			{
				$sql = "SELECT id, title, name, created, pid, lang, raw, hits FROM pastes WHERE private = 0 AND (title LIKE ? OR raw LIKE ?) ORDER BY hits DESC, created DESC LIMIT $page,$amount";
			}
			$query = $this->db->query($sql, array(
				$search,
				$search,
			));
		}
		else
		{

			// count total results
			$sql = "SELECT id FROM pastes WHERE private = 0";
			$query = $this->db->query($sql);
			$total_rows = $query->num_rows();

			// query
			
			if ($this->db->dbdriver == "postgre") 
			{
				$sql = "SELECT id, title, name, created, pid, lang, raw, hits FROM pastes WHERE private = 0 ORDER BY hits DESC, created DESC LIMIT $amount OFFSET $page";
			}
			else
			{
				$sql = "SELECT id, title, name, created, pid, lang, raw, hits FROM pastes WHERE private = 0 ORDER BY hits DESC, created DESC LIMIT $page,$amount";
			}
			$query = $this->db->query($sql);
		}
		
		if ($query->num_rows() > 0) 
		{
			$n = 0;
			foreach ($query->result_array() as $row) 
			{
				$data['pastes'][$n]['id'] = $row['id'];
				$data['pastes'][$n]['title'] = $row['title'];
				$data['pastes'][$n]['name'] = $row['name'];
				$data['pastes'][$n]['created'] = $row['created'];
				$data['pastes'][$n]['lang'] = $this->languages->code_to_description($row['lang']);
				$data['pastes'][$n]['pid'] = $row['pid'];
				$data['pastes'][$n]['raw'] = $row['raw'];
				$data['pastes'][$n]['hits'] = $row['hits'];
				$n++;
			}
		}
		$config['base_url'] = site_url($root);
		$config['total_rows'] = $total_rows;
		$config['per_page'] = $amount;
		$config['num_links'] = 9;
		$config['full_tag_open'] = '<div class="pages">';
		$config['full_tag_close'] = '</div>';
		$config['uri_segment'] = $seg;
		$searchparams = ($this->input->get('search') ? '?search=' . $this->input->get('search') : '');
		$config['first_url'] = '0' . $searchparams;
		$config['suffix'] = $searchparams;
		$this->pagination->initialize($config);
		$data['pages'] = $this->pagination->create_links();
		return $data;
	}
	
	function getSpamLists($root = 'spamadmin/', $seg = 2, $ip_address = false) 
	{
		$this->load->library('pagination');
		$this->load->library('process');
		$amount = $this->config->item('per_page');
		$page = ($this->uri->segment($seg) ? $this->uri->segment($seg) : 0);
		$this->db->select('id, title, name, created, pid, lang, ip_address');
		$this->db->where('private', 0);
		
		if ($ip_address) 
		{
			$this->db->where('ip_address', $ip_address);
		}
		$this->db->order_by('created', 'desc');
		$query = $this->db->get('pastes', $amount, $page);
		
		if ($query->num_rows() > 0) 
		{
			$n = 0;
			foreach ($query->result_array() as $row) 
			{
				$data['pastes'][$n]['id'] = $row['id'];
				$data['pastes'][$n]['title'] = $row['title'];
				$data['pastes'][$n]['name'] = $row['name'];
				$data['pastes'][$n]['created'] = $row['created'];
				$data['pastes'][$n]['lang'] = $row['lang'];
				$data['pastes'][$n]['pid'] = $row['pid'];
				$data['pastes'][$n]['ip_address'] = $row['ip_address'];
				$n++;
			}
		}

		//pagination
		$config['base_url'] = site_url($root);
		$config['total_rows'] = $this->countPastes($ip_address);
		$config['per_page'] = $amount;
		$config['num_links'] = 9;
		$config['full_tag_open'] = '<div class="pages">';
		$config['full_tag_close'] = '</div>';
		$config['uri_segment'] = $seg;
		$this->pagination->initialize($config);
		$data['pages'] = $this->pagination->create_links();

		//total spam attempts
		$this->db->select('SUM(spam_attempts) as sum');
		$query = $this->db->get('blocked_ips');
		$q = $query->result_array();
		$data['total_spam_attempts'] = ($q[0]['sum'] != '' ? $q[0]['sum'] : 0);

		//return
		return $data;
	}
	
	function cron() 
	{
		$now = now();
		$this->db->where('toexpire', '1');
		$query = $this->db->get('pastes');
		foreach ($query->result_array() as $row) 
		{
			$stamp = $row['expire'];
			
			if ($now > $stamp) 
			{
				$this->delete_paste($row['pid']);
			}
		}
		return;
	}
	
	function delete_paste($pid) 
	{
		$this->db->where('pid', $pid);
		$this->db->delete('pastes');

		// delete from trending
		$this->db->where('paste_id', $pid);
		$this->db->delete('trending');
		return;
	}
	
	function random_paste() 
	{
		$this->load->library('process');
		$this->db->order_by('id', 'RANDOM');
		$this->db->limit(1);
		$query = $this->db->get('pastes');
		
		if ($query->num_rows() > 0) 
		{
			foreach ($query->result_array() as $row) 
			{
				$data['title'] = $row['title'];
				$data['pid'] = $row['pid'];
				$data['name'] = $row['name'];
				$data['lang_code'] = $row['lang'];
				$data['lang'] = $this->languages->code_to_description($row['lang']);
				$data['paste'] = $this->process->syntax(htmlspecialchars_decode($row['raw']) , $row['lang']);
				$data['created'] = $row['created'];
				$data['url'] = $this->_get_url($row['pid']);
				$data['raw'] = $row['raw'];
				$data['hits'] = $row['hits'];
				$data['hits_updated'] = $row['hits_updated'];
				$data['snipurl'] = $row['snipurl'];
				$inreply = $row['replyto'];
			}
			
			if ($inreply) 
			{
				$this->db->select('name, title');
				$this->db->where('pid', $inreply);
				$query = $this->db->get('pastes');
				
				if ($query->num_rows() > 0) 
				{
					foreach ($query->result_array() as $row) 
					{
						$data['inreply']['title'] = $row['title'];
						$data['inreply']['name'] = $row['name'];
						$data['inreply']['url'] = site_url('view/' . $inreply);
					}
				}
				else
				{
					$data['inreply'] = false;
				}
			}
			return $data;
		}
		return false;
	}
	private 
	function _format_diff($text) 
	{
		$text = str_replace("\t", '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', $text);
		$text = str_replace("<br />", '<br/>', $text);
		$text = str_replace(" ", '&nbsp;', $text);
		$text = '<div class="text" style="font-family:monospace; font: normal normal 1em/1.2em monospace;">' . $text . '</div>';
		return $text;
	}
	private 
	function _strip_bad_multibyte_chars($str) 
	{
		$result = '';
		$length = strlen($str);
		for ($i = 0;$i < $length;$i++) 
		{

			// Replace four-byte characters (11110www 10zzzzzz 10yyyyyy 10xxxxxx)
			$ord = ord($str[$i]);
			
			if ($ord >= 240 && $ord <= 244) 
			{
				$result.= '?';
				$i+= 3;
			}
			else
			{
				$result.= $str[$i];
			}
		}
		return $result;
	}
}
