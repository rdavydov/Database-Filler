<?php

class DatabaseFiller {

	/**
		*
		* Fill a multi-table MySQL database with junk data through the parsing of the MySQL schema file.
		*
		*
		* Origin:
		*                  I needed to test the population of a database with 14 complex tables. Tools such as Spawner are good on small tables -
		*                  - but specifying the datatypes on so many fields before using Spawner was too time-consuming. Instead, why not parse the SQL schema?
		*
		* Purposes:
		*                  1) Assist in the testing, editing, and data population of complex database schema, before moving the database to a production environment.
		*                  2) Test database connection encoding and character encoding, and data insert speeds on different character encodings.
		*                  3) Check table field population with specified datatype, data truncation, visual cues etc.
		*
		* Requirements:
		*                  1) Script expects database schema to exist in MySQL (mysql -u root -p < test.sql)
		*                  2) ** All table names and column names in the MySQL schema require back-ticks. **
		*                  3) Unique keys must be removed from tables when using the configuration option 'random_data' => FALSE
		*
		* Other:
		*                  Any foreign keys are disabled on data population.
		*                  Random character generation is slow in PHP, and further depends on field length, number of fields, and the number of rows being generated.
		*                  Coded to support PHP 5.4+
		*                  Class could be altered to parse SHOW CREATE TABLE from MySQL directly.
		*
		* @author          Martin Latter <copysense.co.uk>
		* @copyright       Martin Latter 13/12/2014
		* @version         0.44
		* @license         GNU GPL v3.0
		* @link            https://github.com/Tinram/Database-Filler.git
		*
	*/


	private

		# CONFIGURATION DEFAULTS

		# output toggle
		$bDebug = FALSE, # TRUE: verbose screen output and no DB insertion; FALSE: database query insertion

		# number of rows to insert
		$iNumRows = 1,

		# schema file
		$sSchemaFile = NULL,

		# DB connection encoding
		$sEncoding = 'utf8',

		# random character range
		$iLowChar = 33,
		$iHighChar = 126,

		# random data generator toggle
		$bRandomData = TRUE, # FALSE = a much faster fixed character fill (unsuitable with unique indexes, and SET unique_checks = 0 is not sufficient)

		##########################

		$oConnection = FALSE,
		$bActiveConnection = FALSE,

		$sPrimaryKey = '',
		$sUsername = '',

		$sLineBreak = '',

		$aMessages = [];


	/**
		* Set-up configuration class variables, establish DB connection if no debug configuration option set.
		*
		* @param    array $aConfig, configuration details
	*/

	public function __construct(array $aConfig) {

		$this->sLineBreak = (PHP_SAPI !== 'cli') ? '<br>' : "\n";

		if ( ! isset($aConfig['schema_file'])) {

			$this->aMessages[] = 'No schema file specified in the configuration array.';
			return;
		}

		if (isset($aConfig['debug'])) {
			$this->bDebug = $aConfig['debug'];
		}

		if (isset($aConfig['num_rows'])) {
			$this->iNumRows = (int) $aConfig['num_rows'];
		}

		if (isset($aConfig['random_data'])) {
			$this->bRandomData = $aConfig['random_data'];
		}

		if (isset($aConfig['low_char'])) {
			$this->iLowChar = (int) $aConfig['low_char'];
		}

		if (isset($aConfig['high_char'])) {
			$this->iHighChar = (int) $aConfig['high_char'];
		}

		if ( ! $this->bDebug) {

			if ( ! isset($aConfig['host']) || ! isset($aConfig['database']) || ! isset($aConfig['username']) || ! isset($aConfig['password'])) {

				$this->aMessages[] = 'Database connection details have not been fully specified in the configuration array.';
				return;
			}

			if (isset($aConfig['encoding'])) {
				$this->sEncoding = $aConfig['encoding'];
			}

			$this->oConnection = new mysqli($aConfig['host'], $aConfig['username'], $aConfig['password'], $aConfig['database']);

			if ( ! $this->oConnection->connect_errno) {

				$this->oConnection->set_charset($this->sEncoding);
				$this->bActiveConnection = TRUE;

				$this->sUsername = $aConfig['username'];
			}
			else {

				$this->aMessages[] = 'Database connection failed: ' . $this->oConnection->connect_error . ' (error number: ' . $this->oConnection->connect_errno . ')';
				return;
			}
		}

		$this->parseSQLFile($aConfig['schema_file']);

	} # end __construct()


	/**
		* Close DB connection if active.
	*/

	public function __destruct() {

		if ($this->bActiveConnection) {
			$this->oConnection->close();
		}

	} # end __destruct()


	/**
		* Parse SQL file to extract table schema.
		*
		* @param    string $sFileName, schema filename
	*/

	private function parseSQLFile($sFileName) {

		$aTableHolder = [];
		$aMatch = [];

		if ( ! file_exists($sFileName)) {
			$this->aMessages[] = 'The schema file \'' . htmlentities(strip_tags($sFileName)) . '\' does not exist in this directory.';
			return;
		}

		# parse SQL schema
		$sFile = file_get_contents($sFileName);

		# find number of instances of 'CREATE TABLE'
		preg_match_all('/CREATE TABLE/', $sFile, $aMatch);

		# create array of table info
		for ($i = 0, $iOffset = 0, $n = sizeof($aMatch[0]); $i < $n; $i++) {

			if ( ! $iOffset) {

				$iStart = stripos($sFile, 'CREATE TABLE');
				$iEnd = stripos($sFile, 'ENGINE=');
			}
			else {

				$iStart = stripos($sFile, 'CREATE TABLE', $iEnd);
				$iEnd = stripos($sFile, 'ENGINE=', $iStart);
			}

			$sTable = substr($sFile, $iStart, ($iEnd - $iStart));

			$iOffset = $iEnd;

			# remove COMMENT 'text', including most common symbols; preserve schema items after COMMENT
			$sTable = preg_replace('/comment [\'|"][\w\s,;:`<>=£&%@~#\\\.\/\{\}\[\]\^\$\(\)\|\!\*\?\-\+]*[\'|"]/i', '', $sTable);

			# strip SQL comments
			$sTable = preg_replace('!/\*.*?\*/!s', '', $sTable); # credit: chaos, stackoverflow
			$sTable = preg_replace('/[\s]*(--|#).*[\n|\r\n]/', "\n", $sTable);

			# replace EOL and any surrounding spaces for split
			$sTable = preg_replace('/[\s]*,[\s]*[\n|\r\n]/', '*', $sTable);

			$aTableHolder[] = $sTable;
		}

		# send each table string for processing
		foreach ($aTableHolder as $sTable) {
			$this->processSQLTable($sTable);
		}

	} # end parseSQLFile()


	/**
		* Process each table schema.
		*
		* @param    string $sTable, table schema string
	*/

	private function processSQLTable($sTable) {

		static $iCount = 1;

		$fD1 = microtime(TRUE);

		$aDBFieldAttr = [];
		$aRXResults = [];
		$aFields = [];
		$aValues = [];

		# parse primary key name
		$iPKStart = stripos($sTable, 'PRIMARY KEY');
		$iPKEnd = strpos($sTable, ')', $iPKStart);
		$sPKCont = substr($sTable, $iPKStart, $iPKEnd);
		preg_match('/`([\w\-]+)`/', $sPKCont, $aRXResults);
		$this->sPrimaryKey = $aRXResults[1]; # class var rather than passing a function parameter for each line

		$aLines = explode('*', $sTable);

		# get table name
		preg_match('/`([\w\-]+)`/', $aLines[0], $aRXResults);
		$sTableName = $aRXResults[1];

		# extract field attributes
		foreach ($aLines as $sLine) {

			$aTemp = $this->findField($sLine);

			if ( ! is_null($aTemp)) {
				$aDBFieldAttr[] = $aTemp;
			}
		}
		##

		# create SQL query field names
		foreach ($aDBFieldAttr as $aRow) {
			$aFields[] = '`' . $aRow['fieldName'] . '`';
		}
		##

		# create SQL query value sets
		for ($i = 0; $i < $this->iNumRows; $i++) {

			$aTemp = []; # reset

			# generate random data for fields, dependent on datatype
			foreach ($aDBFieldAttr as $aRow) {

				if ($aRow['type'] === 'string') {

					$iLen = (int) $aRow['length'];

					if ( ! $iLen) {
						$iLen = 255;
					}

					$s = '';

					if ($this->bRandomData) {

						for ($j = 0; $j < $iLen; $j++) {

							$c = chr(mt_rand($this->iLowChar, $this->iHighChar));

							if ($c === '<' || $c === '>') { # < and > are corrupting symbols
								$c = 'Z';
							}

							$s .= $c;
						}
					}
					else {

						for ($j = 0; $j < $iLen; $j++) {
							$s .= 'X';
						}
					}

					$aTemp[] = '"' . addslashes($s) . '"';
				}
				else if (substr($aRow['type'], 0, 3) === 'int') {

					$MAXINT = mt_getrandmax(); # limited by PHP

					switch ($aRow['type']) {

						case 'int_32' :

							if ($aRow['unsigned']) {
								$iMin = 0;
								$iMax = $MAXINT;
							}
							else {
								# skew to get predominantly negative values
								$iMin = -9999999;
								$iMax = 1000000;
							}

						break;

						case 'int_24' :

							if ($aRow['unsigned']) {
								$iMin = 0;
								$iMax = 16777215;
							}
							else {
								$iMin = -8388608;
								$iMax = 8388607;
							}

						break;

						case 'int_16' :

							if ($aRow['unsigned']) {
								$iMin = 0;
								$iMax = 65535;
							}
							else {
								$iMin = -32768;
								$iMax = 32767;
							}

						break;

						case 'int_8' :

							if ($aRow['unsigned']) {
								$iMin = 0;
								$iMax = 255;
							}
							else {
								$iMin = -128;
								$iMax = 127;
							}

						break;

						case 'int_64' :

							# int_64 dealt with separately for 32-bit limits
							$iMin = 0;
							$iMax = 18446744073708551616; # reduced slightly to avoid 'out of range' error for 'random_data' => FALSE

						break;
					}

					if ($this->bRandomData) {

						if ($aRow['type'] !== 'int_64') {
							$iNum = mt_rand($iMin, $iMax);
						}
						else {
							# BIGINT string kludge for 32-bit systems
							$s = '';
							for ($j = 0; $j < 19; $j++) { # 1 char less than max to avoid overflow
								$s .= mt_rand(0, 9);
							}
							$iNum = $s;
						}
					}
					else {
						$iNum = $iMax;
					}

					$aTemp[] = $iNum;
				}
				else if ($aRow['type'] === 'decimal' || substr($aRow['type'], 0, 5) === 'float') {

					# compromise dealing with decimals and floats

					if ($aRow['type'] === 'decimal') {
						$iLen = ((int) $aRow['length']) - 3;
					}
					else {
						$iLen = (int) $aRow['length'];
					}

					$s = '';

					for ($j = 0; $j < $iLen; $j++) {
						$s .= 9;
					}

					$iMax = (int) $s;

					if ($this->bRandomData) {

						$iNum = mt_rand(0, $iMax);
						$iUnits = mt_rand(0, 99);
					}
					else {

						$iNum = $s;
						$iUnits = '50';
					}

					if ($aRow['type'] === 'decimal') {
						$aTemp[] = '"' . $iNum . '.' . $iUnits . '"';
					}
					else if ($aRow['type'] === 'float_single') {
						$aTemp[] = lcg_value() * 1000000; # for 32-bit float behaviour
					}
					else if ($aRow['type'] === 'float_double') {
						$aTemp[] = lcg_value() * $iMax;
					}
				}
				else if ($aRow['type'] === 'date') {

					$aTemp[] = '"' . date('Y-m-d') . '"';
				}
				else if ($aRow['type'] === 'datetime') {

					$aTemp[] = '"' . date('Y-m-d H:i:s') . '"';
				}
				else if ($aRow['type'] === 'time') {

					$aTemp[] = '"' . date('H:i:s') . '"';
				}
				else if ($aRow['type'] === 'enumerate') {

					$aTemp[] = '"' . $aRow['enumfields'][array_rand($aRow['enumfields'])] . '"';
				}
			}

			$aValues[] = '(' . join(',', $aTemp) . ')';
		}
		##

		$fD2 = microtime(TRUE);
		$this->aMessages[] = __METHOD__ . '() iteration <b>' . $iCount . '</b> :: ' . sprintf('%01.6f sec', $fD2 - $fD1);

		if ($this->bDebug) {

			$this->aMessages[] = var_dump($aFields);
			$this->aMessages[] = var_dump($aValues);
		}

		# create SQL query string
		$sInsert = 'INSERT INTO ' . $sTableName . ' ';
		$sInsert .= '(' . join(',', $aFields) . ') ';
		$sInsert .= 'VALUES ' . join(',', $aValues);

		if ($this->bDebug) {
			$this->aMessages[] = $sInsert . $this->sLineBreak;
		}
		##

		# send SQL to database
		if ( ! $this->bDebug) {

			$this->oConnection->query('SET foreign_key_checks = 0');

			if ($this->sUsername === 'root' && $this->iNumRows > 1500) { # adjust value as necessary, 1500 was originally for Win XAMPP

				# the following variable can be set when running as root / super (affecting all connections)
				# other useful variables for inserts need to be directly edited in my.cnf / my.ini
				$this->oConnection->query('SET GLOBAL max_allowed_packet = 268435456');
			}

			$fT1 = microtime(TRUE);
			$rResult = $this->oConnection->query($sInsert);
			$fT2 = microtime(TRUE);

			if ($rResult) {
				$this->aMessages[] = 'added ' . $this->iNumRows . ' rows of ' . ($this->bRandomData ? 'random' : 'fixed') . ' data to table <b>' . $sTableName . '</b>';
			}
			else {

				$this->aMessages[] = 'there were <b>ERRORS</b> attempting to add ' . $this->iNumRows . ' rows of ' . ($this->bRandomData ? 'random' : 'fixed') . ' data to table <b>' . $sTableName . '</b>';
				$rResult = $this->oConnection->query('SHOW WARNINGS');
				$aErrors = $rResult->fetch_row();
				$rResult->close();
				$this->aMessages[] = join(' | ', $aErrors);
			}

			$this->aMessages[] = 'SQL insertion: ' . sprintf('%01.6f sec', $fT2 - $fT1) . $this->sLineBreak;
		}
		##

		$iCount++;

	} # end processSQLTable()


	/**
		* Extract field data from schema line.
		*
		* @param    string $sLine, line
		* @return   array ( 'fieldName' => $v, 'type' => $v, 'length' => $v )
	*/

	private function findField($sLine) {

		static $aTypes = [

			'BIGINT' => 'int_64',
			'TINYINT' => 'int_8',
			'SMALLINT' => 'int_16',
			'MEDIUMINT' => 'int_24',
			'INT' => 'int_32', # catch other ints before int_32

			'DECIMAL' => 'decimal',
			'FLOAT' => 'float_single',
			'DOUBLE' => 'float_double',

			'CHAR' => 'string',
			'VARCHAR' => 'string',

			'TEXT' => 'string',
			'TINYTEXT' => 'string',
			'MEDIUMTEXT' => 'string',
			'LONGTEXT' => 'string',

			'ENUM' => 'enumerate',

			'DATETIME' => 'datetime',
			'DATE' => 'date',
			'TIME' => 'time'

		];


		if (stripos($sLine, 'CREATE TABLE') !== FALSE || stripos($sLine, 'KEY') !== FALSE || stripos($sLine, 'TIMESTAMP') !== FALSE) {
			return NULL;
		}

		$aOut = ['type' => '', 'length' => 0]; # set defaults to address notices
		$aRXResults = [];

		foreach ($aTypes as $sType => $v) {

			$iPos = stripos($sLine, $sType);

			if ($iPos !== FALSE) {

				$sSub = substr($sLine, $iPos);

				preg_match('/([0-9]+)/', $sSub, $aRXResults);
				$aOut['type'] = $v;

				if ($aOut['type'] !== 'datetime' && $aOut['type'] !== 'date') {
					$aOut['length'] = @$aRXResults[1]; # block for comments in SQL schema
				}

				# ENUMeration
				if ($aOut['type'] === 'enumerate') {

					$sEnumParams = substr($sLine, strpos($sLine, '('), strpos($sLine, ')'));
					$sEnumParams = str_replace( ['\'', '"', '(', ')', ' '], '', $sEnumParams);
					$aOut['enumfields'] = explode(',', $sEnumParams);
				}

				break;
			}
		}

		preg_match('/`([\w\-]+)`/', $sLine, $aRXResults);

		$aOut['unsigned'] = (stripos($sLine, 'unsigned') !== FALSE) ? TRUE : FALSE;

		if ( ! empty($aRXResults[1]) && $aRXResults[1] !== $this->sPrimaryKey) {

			$aOut['fieldName'] = $aRXResults[1];
			return $aOut;
		}

	} # end findField()


	/**
		* Getter for class array of messages.
		*
		* @return   string
	*/

	public function displayMessages() {

		return $this->sLineBreak . join($this->sLineBreak, $this->aMessages) . $this->sLineBreak;

	} # end displayMessages()

} # end {}

?>