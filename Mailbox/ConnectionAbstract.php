<?php
declare(strict_types=1);
namespace Mailbox;

use Mailbox\ConnectionInterface;

abstract class ConnectionAbstract implements ConnectionInterface {
	
	const CRLF = "\r\n";
	
	// https://datatracker.ietf.org/doc/html/rfc3501#section-6.1
	const COMMAND_ANY = 0;
	
	// https://datatracker.ietf.org/doc/html/rfc3501#section-6.2
	const COMMAND_NOT_AUTHENTICATED = 1;
	
	// https://datatracker.ietf.org/doc/html/rfc3501#section-6.3
	const COMMAND_AUTHENTICATED = 2;
	
	// https://datatracker.ietf.org/doc/html/rfc3501#section-6.4
	const COMMAND_SELECTED = 4;
	
	static protected $commands = [
		'CAPABILITY' 	=> self::COMMAND_ANY,
		'NOOP' 			=> self::COMMAND_ANY,
		'LOGOUT' 		=> self::COMMAND_ANY,
		'STARTTLS' 		=> self::COMMAND_NOT_AUTHENTICATED,
		'AUTHENTICATE' 	=> self::COMMAND_NOT_AUTHENTICATED,
		'LOGIN' 		=> self::COMMAND_NOT_AUTHENTICATED,
		'SELECT' 		=> self::COMMAND_AUTHENTICATED,
		'EXAMINE' 		=> self::COMMAND_AUTHENTICATED,
		'CREATE' 		=> self::COMMAND_AUTHENTICATED,
		'DELETE' 		=> self::COMMAND_AUTHENTICATED,
		'RENAME' 		=> self::COMMAND_AUTHENTICATED,
		'SUBSCRIBE' 	=> self::COMMAND_AUTHENTICATED,
		'UNSUBSCRIBE' 	=> self::COMMAND_AUTHENTICATED,
		'LIST' 			=> self::COMMAND_AUTHENTICATED,
		'LSUB' 			=> self::COMMAND_AUTHENTICATED,
		'STATUS' 		=> self::COMMAND_AUTHENTICATED,
		'APPEND' 		=> self::COMMAND_AUTHENTICATED,
		'CHECK' 		=> self::COMMAND_SELECTED,
		'CLOSE' 		=> self::COMMAND_SELECTED,
		'EXPUNGE' 		=> self::COMMAND_SELECTED,
		'SEARCH' 		=> self::COMMAND_SELECTED,
		'FETCH' 		=> self::COMMAND_SELECTED,
		'STORE' 		=> self::COMMAND_SELECTED,
		'COPY' 			=> self::COMMAND_SELECTED,
		'UID' 			=> self::COMMAND_SELECTED
	];
	
	const STATUS_MESSAGES = 1;
	const STATUS_RECENT = 2;
	const STATUS_UIDNEXT = 4;
	const STATUS_UIDVALIDITY = 8;
	const STATUS_UNSEEN = 16;
	const STATUS_ALL = 31;
	
	const FLAG_NONE = 0;
	const FLAG_UID = 1;
	const FLAG_PEEK = 2;
	
	protected $options;
	
	protected $capabilities = array();
	
	protected $isSelected = false;
	
	protected $selectedFolder;
	
	public $results = [];
	
	public $errorCode = 'OK';
	
	public $errorText = '';
	
	public $isContinuation = false;
	
	protected $connection;
	
	/**
	 * 
	 */
	public function __destruct() {
		$this->disconnect();
	}
	
	
	/**
	 * 
	 */
	public function disconnect():void {
		// When in selected state, execute CLOSE command to close current selected folder first.
		if ($this->isSelected):
			$this->command('CLOSE');
		endif;
		$this->command('LOGOUT');
	}
	
	
	/**
	 * 
	 */
	public function toUTF8(string $string): string {
		return mb_convert_encoding($string, 'UTF-8', 'UTF7-IMAP');
	}
	
	
	/**
	 * Encode ISO-8859-1 or UTF-8 string into UTF7-IMAP
	 */
	public function toUTF7Imap(string $string):string {
		$fromEncoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1'], true);
		if (false === $fromEncoding):
			throw new \ErrorException('Invalid encoding');
		endif;
		return mb_convert_encoding($string, 'UTF7-IMAP', $fromEncoding);
	}
	
	
	/**
	 * 
	 */
	abstract public function isConnected();
	
	
	/**
	 * 
	 */
	public function isAuthenticated():bool {
		return (isset($this->capabilities['AUTH']) === false);
	}
	
	
	/**
	 * 
	 */
	public function isSelected():bool {
		return $this->isSelected;
	}
	
	
	/**
	 * 
	**/
	public function getSelectedFolder():?string {
		return $this->selectedFolder;
	}
	
	
	/**
	 * 
	 */
	public function getCapability(string $name) {
		if (isset($this->capabilities[$name])):
			return $this->capabilities[$name];
		endif;
	}
	
	
	
	/**
	 * The NOOP command always succeeds.  It does nothing.
	 * Since any command can return a status update as untagged data, the NOOP command can be used as a periodic poll for new messages or 
	 * message status updates during a period of inactivity (this is the preferred method to do this).
	 * The NOOP command can also be used to reset any inactivity autologout timer on the server.
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.2
	 */
	public function noop():bool {
		return $this->command('NOOP');
	}
	
	
	
	/**
	 * Indicates a SASL authentication mechanism to the server.
	 * If the server supports the requested authentication mechanism, it performs an authentication protocol 
	 * exchange to authenticate and identify the client.
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.2.2
	 */
	public function authenticate():bool {
		if (false === is_array($this->capabilities['AUTH'])):
			$this->capabilities['AUTH'] = [$this->capabilities['AUTH']];
		endif;
		
		if (false === isset($this->options['auth_type'])):
			return $this->login($this->options['username'], $this->options['password']);
		endif;
		
		if (false === in_array($this->options['auth_type'], $this->capabilities['AUTH'])):
			throw new \ErrorException(sprintf('Unsupported authentication mechanism: %s by server.', $this->options['auth_type']));
		endif;
		
		$commands = array();
		$username = $this->toUTF8($this->options['username']);
		$password = $this->toUTF8($this->options['password']);
		switch ($this->options['auth_type']):
			case 'PLAIN':
				//Format from https://tools.ietf.org/html/rfc4616#section-2
				// https://datatracker.ietf.org/doc/html/rfc4959
				$commands[] = 'AUTHENTICATE PLAIN ' .  base64_encode(sprintf("\0%s\0%s", $username, $password));
			break;
			
			case 'LOGIN':
				$commands = array();
				$commands[] = 'AUTHENTICATE LOGIN';
				$commands[] = base64_encode($username);
				$commands[] = base64_encode($username);
			break;
			
			case 'XOAUTH2':
				if (empty($this->options['access_token'])):
					throw new \ErrorException('Configuration "access_token" required for auth type %s XOAUTH2');
				endif;
				$auth = base64_encode(sprintf("user=%s\001auth=Bearer %s\001\001", $username, $this->options['access_token']));
				$commands[] = 'AUTHENTICATE XOAUTH2 ' . $auth;
				break;
				
			case 'XOAUTHBEARER':
			case 'PLAIN-CLIENTTOKEN':
			case 'CRAM-MD5':
				// @todo: this is just a reminder to do it later once there is a chance to try it
				// I am not familiar with these authenticatication. If you have an email server that supported with this authentication, please let me know
			default:
				throw new \ErrorException(sprintf('Unsupported authentication mechanism: %s by client.', $this->options['auth_type']));
			break;
		endswitch;
		
		foreach ($commands as $command):
			$result = $this->command($command, $commandTag);
			if (false === $result):
				break;
			endif;
		endforeach;
		return $result;
	}
	
	
	
	/**
	 * Informs the server that the client is done with the connection.
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.3
	 */
	public function logout():bool {
		return $this->command('LOGOUT');
	}
	
	
	/**
	 * Identifies the client to the server and carries the plaintext password authenticating this user.
	 * @param string
	 * @param string
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.2.3
	 */
	public function login(string $username, string $password):bool {
		$command = sprintf('LOGIN %s %s', $username, $password);
		return $this->command($command);
	}
	
	
	/**
	 * Selects a mailbox so that messages in the mailbox can be accessed.
	 * @param string
	 * @return object Mailbox
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.1
	 */
	public function select(string $mailbox, bool $readonly = false) {
		return new \Mailbox\Mailbox($this, $mailbox, $readonly);
	}
	
	
	/**
	 * Creates a mailbox with the given name.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.3
	 */
	public function create(string $mailbox):bool {
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$mailbox = $this->toUTF7Imap($mailbox);
		endif;
		return $this->command(sprintf('CREATE "%s"', $mailbox));
	}
	
	
	/**
	 * Permanently removes the mailbox with the given name.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.4
	 */
	public function delete(string $mailbox): bool {
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$mailbox = $this->toUTF7Imap($mailbox);
		endif;
		return $this->command(sprintf('DELETE "%s"', $mailbox));
	}
	
	
	/**
	 * Changes the name of a mailbox.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.5
	 */
	public function rename(string $oldMailbox, string $newMailbox):bool {
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$oldMailbox = $this->toUTF7Imap($oldMailbox);
			$newMailbox = $this->toUTF7Imap($newMailbox);
		endif;
		return $this->command(sprintf('RENAME "%s" "%s"', $oldMailbox, $newMailbox));
	}
	
	
	/**
	 * Adds the specified mailbox name to the server's set of "active" or "subscribed" mailboxes as returned by the LSUB command.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.6
	 */
	public function subscribe(string $mailbox):bool {
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$mailbox = $this->toUTF7Imap($mailbox);
		endif;
		return $this->command(sprintf('SUBSCRIBE "%s"', $mailbox));
	}
	
	
	/**
	 * Removes the specified mailbox name from the server's set of "active" or "subscribed" mailboxes as returned by the LSUB command.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.7
	 */
	public function unsubscribe(string $mailbox):bool {
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$mailbox = $this->toUTF7Imap($mailbox);
		endif;
		return $this->command(sprintf('UNSUBSCRIBE "%s"', $mailbox));
	}
	
	
	/**
	 * Returns a subset of names from the complete set of all names available to the client.
	 * @param string mailbox name with possible wildcards
	 * @param string reference name
	 * @return object ArrayObject | false
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.8
	 */
	public function list(string $reference = '', string $mailbox = '*') {
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$reference = $this->toUTF7Imap($reference);
			$mailbox = $this->toUTF7Imap($mailbox);
		endif;
		$command = sprintf('LIST "%s" "%s"', $reference, $mailbox);
		if (false === $this->command($command)):
			return [];
		endif;
		return isset($this->results['LIST']) ? $this->results['LIST'] : [];
	}
	
	
	/**
	 * Returns a subset of names from the set of names that the user has declared as being "active" or "subscribed".
	 * @param string reference name
	 * @param string mailbox name with possible wildcards
	 * @return object ArrayObject | false
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.9
	 */
	public function lsub(string $reference = '', string $mailbox = '*') {
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$reference = $this->toUTF7Imap($reference);
			$mailbox = $this->toUTF7Imap($mailbox);
		endif;
		$command = sprintf('LSUB "%s" "%s"', $reference, $mailbox);
		if (false === $this->command($command)):
			return [];
		endif;
		return isset($this->results['LSUB']) ? $this->results['LSUB'] : [];
	}
	
	
	/**
	 * Requests the status of the indicated mailbox.
	 * @param string mailbox name
	 * @param int status flags:
	 * - self::STATUS_MESSAGES The number of messages in the mailbox.
	 * - self::STATUS_RECENT The number of messages with the \Recent flag set.
	 * - self::STATUS_UIDNEXT The next unique identifier value of the mailbox.
	 * - self::STATUS_UIDVALIDITY The unique identifier validity value of the mailbox.
	 * - self::STATUS_UNSEEN The number of messages which do not have the \Seen flag set.
	 * - self::STATUS_ALL All statuses.
	 * @return object ArrayObject
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.10
	 */
	public function status(string $mailbox, int $flags = self::STATUS_ALL):array {
		$status = [];
		if ($flags & self::STATUS_MESSAGES):
			$status[] = 'MESSAGES';
		endif;
		if ($flags & self::STATUS_RECENT):
			$status[] = 'RECENT';
		endif;
		if ($flags & self::STATUS_UIDNEXT):
			$status[] = 'UIDNEXT';
		endif;
		if ($flags & self::STATUS_UIDVALIDITY):
			$status[] = 'UIDVALIDITY';
		endif;
		if ($flags & self::STATUS_UNSEEN):
			$status[] = 'UNSEEN';
		endif;
		if (empty($status)):
			throw new \ErrorException('Parameter 2 required');
		endif;
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$mailbox = $this->toUTF7Imap($mailbox);
		endif;
		$command = sprintf('STATUS "%s" (%s)', $mailbox, implode(' ', $status));
		if (false === $this->command($command)):
			return [];
		endif;
		return isset($this->results['STATUS']) ? $this->results['STATUS'] : [];
	}
	
	
	/**
	 * Appends the literal argument as a new message to the end of the specified destination mailbox.
	 * This argument SHOULD be in the format of an [RFC-2822] message.  8-bit characters are permitted 
	 * in the message.  A server implementation that is unable to preserve 8-bit data properly MUST be 
	 * able to reversibly convert 8-bit APPEND data to 7-bit using a [MIME-IMB] content transfer encoding.
	 * @param string
	 * @param array 
	 * @param array
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.11
	public function append(string $mailbox, array $message, array $flags = []):bool {
	}
	 */
	
	
	/**
	 * Parse CAPABILITY response that occurs as a result of a CAPABILITY command.
	 * @param string
	 * @return object ArrayObject
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.1
	 */
	protected function parseCapability(string $string):\ArrayObject {
		$capabilities = [];
		$data = preg_split('/[ ]+/u', trim($string));
		foreach ($data as $item):
			if (false === strpos($item, '=')):
				$capabilities[$item] = true;
				continue;
			endif;
			@list($key, $value) = explode( '=', $item);
			if (false === (isset($capabilities[$key]) && is_array($capabilities[$key]))):
				$capabilities[$key] = [];
			endif;
			$capabilities[$key][] = $value;
		endforeach;
		return new \ArrayObject($capabilities);
	}
	
	
	/**
	 * Parse LIST and LSUB response that occurs as a result of a LIST or LSUB command.
	 * @param string
	 * @return object
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.2
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.3
	 */
	protected function parseList(string $string):\stdClass {
		$regexp = '@^\(([^\)]*)\)[ ]+("(?:[^"]*)"|(?:[^\s]+))[ ]+("(?:[^"]*)"|(?:[^\s]+))$@u';
		if (false === (bool)preg_match($regexp, trim($string), $match)):
			throw new \ErrorException('Parse LIST response failed');
		endif;
		array_shift($match);
		$match = array_map('trim', $match);
		$object = new \stdClass;
		$object->attributes = empty($match[0]) ? [] : preg_split('@[ ]+@u', $match[0]);
		$object->delimiter = trim($match[1], '"');
		$object->name = $this->toUTF8($match[2]);
		return $object;
	}
	
	
	/**
	 * Parse STATUS response that occurs as a result of a STATUS command.
	 * @param string
	 * @return array
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.4
	 */
	protected function parseStatus(string $string):array {
		if (false === (bool)preg_match('/\(([^\)]+)\)/u', $string, $match)):
			throw new \ErrorException('Parse STATUS response failed');
		endif;
		if (false === (bool)preg_match_all('/((MESSAGES|RECENT|UIDNEXT|UIDVALIDITY|UNSEEN)\s+([0-9]+))/', $match[1], $matches, PREG_PATTERN_ORDER)):
			throw new \ErrorException('Parse STATUS response failed');
		endif;
		return array_combine($matches[2], array_map('intval', $matches[3]));
	}
	
	
	/**
	 * Parse SEARCH response that occurs as a result of a SEARCH or UID SEARCH command.
	 * @param string
	 * @return array
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.5
	 */
	protected function parseSearch(string $string):array {
		if (false === (bool)preg_match_all('@([0-9]+)@u', $string, $match)):
			throw new \ErrorException('Parse SEARCH response failed');
		endif;
		return array_map('intval', $match[1]);
	}
	
	
	/**
	 * Parse FLAGS response that occurs as a result of a SELECT or EXAMINE command.
	 * @param string
	 * @return array
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.6
	 */
	protected function parseFlags(string $string):array {
		if (false === (bool)preg_match('@\(([^\)]*)\)@u', $string, $match)):
			throw new \ErrorException('Parse FLAGS response failed');
		endif;
		$match[1] = trim($match[1]);
		return empty($match[1]) ? [] : preg_split('@[ ]+@', $match[1]);
	}
	
	
	protected function parseFetchResponse(string $string) {
		$results = [];
		$data = array_chunk($this->decode(mb_str_split($string)), 2);
		$count = count($data);
		foreach ($data as $item):
			$name = $item[0];
			if (in_array($name, ['BODY', 'BODYSTRUCTURE'])):
				// https://datatracker.ietf.org/doc/html/rfc2045#section-5.1
				$results[$name] = $this->buildBodyStructure($item[1]);
			elseif ($name === 'ENVELOPE'):
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-9
				$fields = ['date', 'subject', 'from', 'sender', 'reply_to', 'to', 'cc', 'bcc', 'in_reply_to', 'message_id'];
				$results[$name] = new \stdClass;
				foreach ($item[1] as $subkey => $subval):
					if (isset($fields[$subkey])):
						$subkey = $fields[$subkey];
					endif;
					switch ($subkey):
						case 'from':
						case 'sender':
						case 'reply_to':
						case 'to':
						case 'cc':
						case 'bcc':
							if ($subval === null):
								$results[$name]->$subkey = [];
								break;
							endif;
							if (false === is_array($subval)):
								throw new \ErrorException(sprintf('Parse %s response failed. Maybe malformed response', $subkey));
							endif;
							$results[$name]->$subkey = array_map([$this, 'buildAddress'], $subval);
							break;
						case 'subject':
							$results[$name]->$subkey = $this->decodeMimeHeader($subval);
							break;
						default:
							$results[$name]->$subkey = $subval;
					endswitch;
				endforeach;
			else:
				$results[$name] = $item[1];
			endif;
		endforeach;
		return $results;
	}
	
	/**
	 * Parse imap string response
	 * @param string
	 * @param string optional
	 * @return void
	 */
	protected function parseResponse(string $line, ?string &$responseTag = null):void {
		@list($responseTag, $responseStatus, $responseData) = explode(' ', $line, 3);
		
		if ($responseTag === '+'):
			$this->isContinuation = true;
			
		elseif ($responseTag === '*'):
			if (is_numeric($responseStatus)):
				$responseNumber = (int)$responseStatus;
				@list($responseStatus, $responseData) = explode(' ', trim($responseData), 2);
				if ($responseStatus === 'EXPUNGE'):
					if (false === isset($this->results[$responseStatus])):
						$this->results[$responseStatus] = [];
					endif;
					$this->results[$responseStatus][] = $responseNumber;
				elseif (in_array($responseStatus, ['EXISTS', 'RECENT'])):
					$this->results[$responseStatus] = $responseNumber;
				elseif ($responseStatus === 'FETCH'):
					if (false === isset($this->results[$responseStatus][$responseNumber])):
						if (false === isset($this->results[$responseStatus])):
							$this->results[$responseStatus] = [];
						endif;
						$this->results[$responseStatus][$responseNumber] = [];
					endif;
					//$this->results[$responseStatus][$responseNumber][] = $responseData;
					$this->results[$responseStatus][$responseNumber] = $responseData;
				endif;
			elseif (in_array($responseStatus, ['OK', 'NO', 'BAD', 'PREAUTH', 'BYE'])):
				$responseData = trim($responseData);
				$this->errorCode = $responseStatus;
				$this->errorText = $responseData;
				if ((bool)preg_match('@\[([^\]]+)\]@', $responseData, $match)):
					@list($key, $value) = explode(' ', $match[1], 2);
					if ($key === 'CAPABILITY'):
						$this->capabilities = $this->parseCapability($value);
					elseif (in_array($key, ['BADCHARSET', 'PERMANENTFLAGS'])):
						$this->results[$key] = $this->parseFlags($value);
					elseif (in_array($key, ['READ-ONLY', 'READ-WRITE'])):
						$this->results[$key] = true;
					elseif (in_array($key, ['UIDNEXT', 'UIDVALIDITY', 'UNSEEN'])):
						$this->results[$key] = (int)$value;
					elseif (in_array($key, ['ALERT', 'PARSE', 'TRYCREATE'])):
						$this->results[$key] = $value;
					else:
						$this->results[$key] = $value;
					endif;
				endif;
			elseif ($responseStatus === 'CAPABILITY'):
				$this->capabilities = $this->parseCapability(trim($responseData));
			elseif (in_array($responseStatus, ['LIST', 'LSUB'])):
				if (false === isset($this->results[$responseStatus])):
					$this->results[$responseStatus] = [];
				endif;
				$this->results[$responseStatus][] = $this->parseList(trim($responseData));
			elseif (in_array($responseStatus, ['STATUS', 'SEARCH', 'FLAGS'])):
				$method = 'parse' . ucfirst(strtolower($responseStatus));
				$this->results[$responseStatus] = $this->$method(trim($responseData));
			endif;
		else:
			if (in_array($responseStatus, ['OK', 'NO', 'BAD'])):
				$responseData = trim($responseData);
				$this->errorCode = $responseStatus;
				$this->errorText = $responseData;
			elseif (false === empty($this->results['FETCH'])):
				if (function_exists('array_key_last')):
					$sequenceNumber = array_key_last($this->results['FETCH']);
				else:
					$sequenceNumber = end(array_keys($this->results['FETCH']));
				endif;
				//$this->results['FETCH'][$sequenceNumber][] = $line;
				$this->results['FETCH'][$sequenceNumber] .= $line;
			endif;
		endif;
	}
	
	
	/**
	 * Decode body structure format
	 * @param string
	 * @param int
	 * @return array
	 */
	protected function decode(array $characters, int &$index = 0):array {
		//static $index = 0;
		$result = array();
		$length = count($characters);
		$text = '';
		$strlen = '';
		$isQuoted = false;
		$isSection = false;
		$isCurlyBrackets = false;
		//var_dump($text);
		//var_dump($index);
		//var_dump($characters[$index]);
		if (isset($characters[$index]) && $characters[$index] === '('):
			++$index;
		endif;
		
		while ($index < $length):
			//$ord = ord($characters[$index]);
			$char = $characters[$index];
			++$index;
			if ($isQuoted):
				if ($char === '\\'):
					// Skip!!!
				elseif ($char === '"'):
					$isQuoted = false;
				else:
					$text .= $char;
				endif;
			elseif ($isSection):
				if ($char === ']'):
					$isSection = false;
				endif;
				$text .= $char;
			elseif ($isCurlyBrackets):
				if ($char === '}'):
					$isCurlyBrackets = false;
					$strlen = (int)$strlen;
				else:
					$strlen .= $char;
				endif;
			elseif (is_int($strlen)):
				if ($strlen > 0):
					$text .= $char;
					--$strlen;
				else:
					$result[] = $text;
					$strlen = '';
					$text = '';
				endif;
			else:
				if ($char === '"'):
					$isQuoted = true;
				elseif ($char === '['):
					$isSection = true;
					$text .= $char;
				elseif ($char === '('):
					--$index;
					$result[] = $this->decode($characters, $index);
				elseif ($char === '{'):
					$isCurlyBrackets = true;
				elseif (in_array($char, ["\r", "\n"])):
				elseif (in_array($char, [' ', ')'])):
					if ($text !== ''):
						if ($text === 'NIL'):
							$text = null;
						elseif (preg_match('@^[0-9]+$@', $text)):
							$text = (int)$text;
						else:
							$text = trim($text, '"');
						endif;
						$result[] = $text;
						$text = '';
					endif;
					if ($char === ')'):
						break;
					endif;
				else:
					$text .= $char;
				endif;
			endif;
		endwhile;
		if ($index >= $length):
			if ($text !== ''):
				$result[] = $text;
			endif;
			$index = 0;
		endif;
		return $result;
	}
	
	
	/**
	 * 
	 */
	protected function buildBodyStructure($item):\stdClass {
		$basicFields = ['type', 'subtype', 'parameter', 'id', 'description', 'encoding', 'size'];
		$extensionFields = ['md5', 'disposition', 'language', 'location'];
		$length = count($item);
		$key = null;
		$hasParts = is_array($item[0]);
		$fieldIndex = 1;
		$object = new \stdClass;
		for ($i = 0; $i < $length; ++$i):
			$value = $item[$i];
			if ($hasParts):
				if (is_array($value) && $key === null):
					if (false === isset($object->parts)):
						$object->parts = [];
					endif;
					$object->parts[] = $this->buildBodyStructure($value);
					continue;
				endif;
				$object->type = 'multipart';
				if (false === isset($basicFields[$fieldIndex])):
					$basicFields = array_merge($basicFields, $extensionFields);
				endif;
				$key = $basicFields[$fieldIndex];
				++$fieldIndex;
			else:
				if (false === isset($basicFields[$i])):
					if ($object->type === 'text'):
						$basicFields[] = 'lines';
					endif;
					$basicFields = array_merge($basicFields, $extensionFields);
				endif;
				$key = $basicFields[$i];
			endif;
			
			switch ($key):
				case 'type':
				case 'subtype':
				case 'encoding':
					if (is_string($value)):
						$object->$key = strtolower($value);
					endif;
					break;
				
				case 'id':
					if ($value !== null && preg_match('@^\<([^\>]*)\>$@', $value, $match)):
						$value = trim($match[1]);
					endif;
					$object->$key = $value;
					break;
					
				case 'parameter':
					$object->parameter = [];
					if (empty($value) || false === is_array($value)):
						break;
					endif;
					$value = array_chunk($value, 2);
					$countval = count($value);
					for ($j = 0; $j < $countval; ++$j):
						if (false === isset($value[$j][0])):
							continue;
						endif;
						$object->parameter[$j] = new \stdClass;
						$object->parameter[$j]->attribute = strtolower($value[$j][0]);
						$object->parameter[$j]->value = null;
						if (isset($value[$j][1])):
							$object->parameter[$j]->value = $value[$j][1];
						endif;
					endfor;
					break;
				
				case 'disposition':
					$object->disposition = null;
					$object->disposition_parameter = [];
					// Fix bug for outlook that not send inline header for inline attachments
					if ($object->id !== null):
						$object->disposition = 'inline';
					endif;
					if (empty($value) || false === is_array($value)):
						break;
					endif;
					$object->disposition = strtolower($value[0]);
					if (empty($value[1]) || false === is_array($value[1])):
						break;
					endif;
					$value = array_chunk($value[1], 2);
					$countval = count($value);
					for ($j = 0; $j < $countval; ++$j):
						if (false === isset($value[$j][0])):
							continue;
						endif;
						$object->disposition_parameter[$j] = new \stdClass;
						$object->disposition_parameter[$j]->attribute = strtolower($value[$j][0]);
						$object->disposition_parameter[$j]->value = null;
						if (isset($value[$j][1])):
							$object->disposition_parameter[$j]->value = $value[$j][1];
						endif;
					endfor;
					break;
				
				default:
					$object->$key = $value;
			endswitch;
		endfor;
		return $object;
	}
	
	
	/**
	 * Callback for build email address info
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-9
	 */
	protected function buildAddress(array $item):\stdClass {
		$fields = ['name', 'adl', 'mailbox', 'host'];
		$object = new \stdClass;
		$count = count($item);
		for ($i = 0; $i < $count; ++$i):
			$key = $i;
			if (isset($fields[$i])):
				$key = $fields[$i];
			endif;
			$object->$key = $item[$i];
		endfor;
		return $object;
	}
	
	
	/**
	 * decode subject header
	 * @param string
	 * @return string
	 * @see: http://www.faqs.org/rfcs/rfc2047.html
	 */
	public function decodeMimeHeader(string $string):string {
		$charsetList = [];
		if (function_exists('mb_list_encodings')):
			$charsetList = mb_list_encodings();
			$charsetList = array_combine(array_map('strtoupper', $charsetList), $charsetList);
		endif;
		$regexp = '@^=\?'
			. '([^\?]+)' // charset
			. '\?'
			. '([^\?]+)' // encoding
			. '\?'
			. '([^\?]*)' // encoded-text
			. '\?=$@';
		if (preg_match($regexp, $string, $match)):
			$charset = strtoupper($match[1]);
			$encoding = strtoupper($match[2]);
			$string = $match[3];
			if ($encoding === 'B'):
				$string = base64_decode($string);
			elseif ($encoding === 'Q'):
				$string = quoted_printable_decode($string);
				$string = str_replace('_', ' ', $string);
			endif;
			// We expect charset UTF-8
			// There is nothing we can do if the charset is not in the list
			if ($charset !== 'UTF-8' && isset($charsetList[$charset])):
				$string = mb_convert_encoding($string, 'UTF-8', $charsetList[$charset]);
			else:
				//$string = mb_convert_encoding($string, 'UTF-8', 'ASCII');
			endif;			
		endif;
		return $string;
	}
}

?>