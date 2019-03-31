<?php
/*
 * CQPweb: a user-friendly interface to the IMS Corpus Query Processor
 * Copyright (C) 2008-10 Andrew Hardie and contributors
 *
 * See http://cwb.sourceforge.net/cqpweb.php
 *
 * This file is part of CQPweb.
 * 
 * CQPweb is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * CQPweb is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */







/** 
 * @file 
 * 
 * This file contains a library of broadly useful functions. 
 */






/*
 * If mysql extension does not exist, include fake-mysql.inc.php to restore the functions
 * that are actually used and emulate them via mysqli.
 * 
 * This is global code in a library file; normally a no-no.
 * it -only- addresses what files need to be included and which don't.
 */
if  (!extension_loaded('mysql'))
{
	if (!class_exists('mysqli', false))
		exit('CQPweb fatal error: neither mysql nor mysqli is available. Contact the system administrator.');
	else
		require('../lib/fake-mysql.inc.php');
}


















/* 
 * ============================
 * connect/disconnect functions 
 * ============================
 */


/**
 * Creates a global connection to a CQP child process.
 * 
 * If no argument is passed, the global $Corpus is activated if there is one. 
 */
function connect_global_cqp($cqp_corpus_name = NULL)
{
	global $Config;
	global $Corpus;
	global $cqp;

	/* connect to CQP */
	$cqp = new CQP($Config->path_to_cwb, $Config->dir->registry);
	/* select an error handling function */
	$cqp->set_error_handler("exiterror_cqp");
	/* set CQP's temporary directory */
	$cqp->execute("set DataDirectory '{$Config->dir->cache}'");
	/* select corpus */
	if (! empty ($cqp_corpus_name))
		$cqp->set_corpus($cqp_corpus_name);
	else if (! empty ($Corpus->cqp_name))
		$cqp->set_corpus($Corpus->cqp_name);
	/* note that corpus must be (RE)SELECTED after calling "set DataDirectory" */
	
	if ($Config->print_debug_messages)
		$cqp->set_debug_mode(true);
}

/**
 * Disconnects the global CQP child process.
 */
function disconnect_global_cqp()
{
	global $cqp;
	if (isset($cqp))
	{
		$cqp->disconnect();
		unset($GLOBALS['cqp']);
	}
}


/**
 * This function refreshes CQP's internal list of queries currently existing in its data directory
 * 
 * NB should this perhaps be part of the CQP object model?
 * (as perhaps should set DataDirectory!)
 */
function refresh_directory_global_cqp()
{
	global $cqp;
	global $Config;
	global $Corpus;
	
	if (isset($cqp))
	{
		$switchdir = getcwd();
		$cqp->execute("set DataDirectory '$switchdir'");
		$cqp->execute("set DataDirectory '{$Config->dir->cache}'");
		$cqp->set_corpus($Corpus->cqp_name);
		// TODO Question: is this still necessary?
	}
}

/**
 * Creates a global variable $mysql_link containing a connection to the CQPweb
 * database, using the settings in the config file.
 */
function connect_global_mysql()
{
	global $mysql_link;
	global $Config;
	
	/* check for previous connection */
	if ( is_resource($mysql_link) )
		mysql_close($mysql_link);
	
	/* Connect with flag 128 == mysql client lib constant CLIENT_LOCAL_FILES;
	 * this overrules deactivation at PHP's end of LOAD DATA LOCAL. (If L-D-L
	 * is deactivated at the mysqld end, e.g. by my.cnf, this won't help, but 
	 * won't hurt either.) 
	 */ 
	$mysql_link = @mysql_connect($Config->mysql_server, $Config->mysql_webuser, $Config->mysql_webpass, false, 128);
	/* Note, in theory there are performance gains to be had by using a 
	 * persistent connection. However, current judgement is that the risk
	 * of problems is too great to justify doing so. 
	 * MySQLi does link cleanup so once most people are using that, 
	 * persistent connections are more likely to be useful.
	 * 
	 * The use of "@" above is suppress the occurrence of depracation messages about 
	 * the MySQL extension. We won't need it after total MySQLi transition.
	 */
	
	if (! $mysql_link)
		exiterror('MySQL did not connect - please try again later!');
	
	if (! mysql_select_db($Config->mysql_schema, $mysql_link))
		exiterror('Could not set database in MySQL connection - critical error!');
	
	/* utf-8 setting is dependent on a variable defined in config.inc.php */
	if ($Config->mysql_utf8_set_required)
		if (! mysql_set_charset("utf8", $mysql_link))
			exiterror('Could not set character set for MySQL connection - critical error!');

	/* the newer default for "sql_mode" in v 5.7 + of MySQL creates problems for various bits of CQPweb.
	 * In particular, we NEED zeros in dates: a zero date indicates "has never happened", and so on. */ 
	do_mysql_query("set sql_mode = \"\"");
	/* TODO: in future, strict-SQL in the form of STRICT_TRANS_TABLES will continue to be default, 
	 * and NO_ZERO_DATE will again be rolled into STRICT. 
	 * We want to comply with this, longterm. So, we need to fix these issues. 
	 * First, by finding places in the code where zero dates occur and getting rid 
	 * (replace with Unix epoch date, 1970-01-01T00:00 as an alternative "empty" value for dates?)
	 * Second, by running with STRICT switched on until all the problems are filtered out.
	 */
}
/**
 * Disconnects from the MySQL server.
 * 
 * Scripts could easily disconnect mysql_link locally. So this function
 * only exists so there is function-name-symmetry, and (less anally-retentively) so 
 * a script never really has to use mysql_link in the normal way of things. As
 * a consequence mysql_link is entirely contained within this module.
 */
function disconnect_global_mysql()
{
	global $mysql_link;
	if(isset($mysql_link))
		mysql_close($mysql_link);
}


function get_db_version()
{
	list($version) = mysql_fetch_row(do_mysql_query('select value from system_info where setting_name = "db_version"'));
	return $version;
}






/* 
 * =====================
 * MySQL query functions 
 * =====================
 */

/**
 * Should never be called except by do_mysql_query or equivalent function.
 * 
 * It inserts some additional info about what the query is, where it originated,
 * and which user is responsible and embeds it into the query as a MySQL comment.
 */
function do_append_mysql_comment(&$sql)
{
	global $User;
	
	$u = (isset($User->username) ? $User->username : '???');
	$d = date(CQPWEB_UI_DATE_FORMAT);

	$bt = debug_backtrace();
	/* this line pulls out the function that called do_mysql_query() */
	for ($i = 1, $n = count($bt) ; $i < $n && preg_match('/^do_mysql_/', $bt[$i]['function']) ; $i++)
		;
	$f = (isset($bt[$i]['function']) ? $bt[$i]['function'] : '???');

// old version
// 	$a = (count($bt) >= 3 ? $bt[2] : end($bt));
// 	$f = (isset($a['function']) ? $a['function'] : '???');

	$sql = "$sql \n\t/* from User: $u | Function: $f() | $d */";
}


/**
 * Does a MySQL query on the CQPweb database, with error checking.
 * 
 * Auto-connects to the database if necessary.
 * 
 * Note - this function should replace all direct calls to mysql_query,
 * thus avoiding duplication of error-checking code.
 * 
 * Returns the result resource.
 */ 
function do_mysql_query($sql)
{
	global $mysql_link;
	static $last_query_time = 0;
	
	/* auto connect if not yet connected ...  */
	if (NULL === $mysql_link)
		connect_global_mysql();
	/* check for timed-out connection : only if more than 60 seconds */
	if (60 < (time() - $last_query_time))
		if (false === mysql_query("select 1", $mysql_link))
			connect_global_mysql();

	do_append_mysql_comment($sql);
	
	print_debug_message("About to run the following MySQL query:\n\t$sql\n");
	$start_time = time();
	
	$result = mysql_query($sql, $mysql_link);
	
	if (false === $result) 
		exiterror_mysqlquery(mysql_errno($mysql_link), mysql_error($mysql_link), $sql);
	
	$last_query_time = time();
			
	print_debug_message("The query ran successfully in " . (time() - $start_time) . " seconds.\n");
		
	return $result;
}


/**
 * Does a mysql query and puts the result into an output file.
 * 
 * This works regardless of whether the mysql server program (mysqld)
 * is allowed to write files or not.
 * 
 * The mysql $query should be of the form "select [something] FROM [table] [other conditions]" 
 * -- that is, it MUST NOT contain "into outfile $filename", and the FROM must be in capitals. 
 * 
 * The output file is specified by $filename - this must be a full ABSOLUTE path.
 * (Use realpath() to make sure of this!)
 * 
 * Typically used to create a dump file (new format post CWB2.2.101)
 * for input to CQP e.g. in the creation of a postprocessed query. 
 * 
 * Its return value is the number of rows written to file. In case of problem,
 * exiterror_* is called here.
 */
function do_mysql_outfile_query($query, $filename)
{
	global $Config;
	global $mysql_link;
	
	do_append_mysql_comment($query);
	
	if ($Config->mysql_has_file_access)
	{
		/* We should use INTO OUTFILE */
		
		$into_outfile = 'INTO OUTFILE "' . mysql_real_escape_string($filename) . '" FROM ';
		$replaced = 0;
		$query = str_replace("FROM ", $into_outfile, $query, $replaced);
		
		if ($replaced != 1)
			exiterror_mysqlquery('no_number',
				'An outfile query was requested which does not contain FROM, or contains multiple instances of FROM.' 
				, $query);
		
		print_debug_message("About to run the following MySQL query:\n\n$query\n");
		$result = mysql_query($query);
		if ($result == false)
			exiterror_mysqlquery(mysql_errno($mysql_link), mysql_error($mysql_link), $query);
		else
		{
			print_debug_message("The query ran successfully.\n");
			return mysql_affected_rows($mysql_link);
		}
	}
	else 
	{
		/* we cannot use INTO OUTFILE, so run the query, and write to file ourselves */
		
		print_debug_message("About to run the following MySQL query:\n\n$query\n");
		$result = mysql_unbuffered_query($query, $mysql_link); /* avoid memory overhead for large result sets */
		if ($result == false)
			exiterror_mysqlquery(mysql_errno($mysql_link), mysql_error($mysql_link), $query);
		print_debug_message("The query ran successfully.\n");
	
		if (!($fh = fopen($filename, 'w'))) 
			exiterror("MySQL outfile query: Could not open file for write ( $filename )");
		
		$rowcount = 0;
		
		while ($row = mysql_fetch_row($result)) 
		{
			fputs($fh, implode("\t", $row) . "\n");
			$rowcount++;
		}
		
		mysql_free_result($result);
		
		fclose($fh);
		
		return $rowcount;
	}
}


/**
 * Loads a specified text file into the given MySQL table.
 * 
 * Note: this is done EITHER with LOAD DATA (LOCAL) INFILE, OR
 * with a loop across the lines of the file.
 * 
 * The latter is EXTREMELY inefficient, but necessary if we're 
 * working on a box where LOAD DATA (LOCAL) INFILE has been 
 * disabled.
 * 
 * If $no_escapes is true, "FIELDS ESCAPED BY" behaviour is 
 * set to an empty string (otherwise it is not specified).
 * 
 * Function returns the (last) update/import query result if
 * all went well; false in case of error.
 */
function do_mysql_infile_query($table, $filename, $no_escapes = true)
{
	global $Config;
	
	/* check variables */
	if (! is_file($filename))
		return false;
	$table = mysql_real_escape_string($table);
	
	/* massive if/else: overall two branches. */
	
	if (! $Config->mysql_infile_disabled)
	{
		/* the normal sensible way */
		
		$filename = mysql_real_escape_string(realpath($filename)); /* because "infile" requries an absolute path when non-LOCAL */
		
		$sql = "{$Config->mysql_LOAD_DATA_INFILE_command} '$filename' INTO TABLE `$table`";
		if ($no_escapes)
			$sql .= ' FIELDS ESCAPED BY \'\'';
		return do_mysql_query($sql);
	}
	else
	{
		/* the nasty hacky workaround way */
		
		/* first we need to find out about the table ... */
		$fields = array();
		
		/* note: we currently allow for char, varchar, and text as "quote-needed"
		 * types, because those are the ones CQPweb uses. There are, of course,
		 * others. See the MySQL manual. */
		
		$result = do_mysql_query("describe $table");
		while (false !== ($f = mysql_fetch_object($result)))
		{
			/* format of "describe" is such that "Field" contains the fieldname,
			 * and "Type" its type. All types should be lowercase, but let's make sure */
			$f->Type = strtolower($f->Type);
			$quoteme =    /* quoteme equals the truth of the following long condition. */
				(
					substr($f->Type, 0, 7) == 'varchar'
					||
					$f->Type == 'text'
					||
					substr($f->Type, 0, 4) == 'char'
				);
			$fields[] = array('field' => $f->Field, 'quoteme' => $quoteme);	
		}
		unset($result);
		
		$source = fopen($filename, 'r');
		
		/* loop across lines in input file */
		while (false !== ($line = fgets($source)))
		{
			/* necessary for security, but might possibly lead to data being
			 * escaped where we don't want it; if so, tant pis */
			$line = mysql_real_escape_string($line);
			$line = rtrim($line, "\r\n");
			$data = explode("\t", $line);

			
			$blob1 = $blob2 = '';
			
			for ( $i = 0 ; true ; $i++ )
			{
				/* require both a field and data; otherwise break */
				if (!isset($data[$i], $fields[$i]))
					break;
				$blob1 .= ", `{$fields[$i]['field']}`";
				
				if ( (! $no_escapes) && $data[$i] == '\\N' )
					/* data for this field is NULL, so type doesn't matter */
					$blob2 .= ', NULL';
				else 
					if ( $fields[$i]['quoteme'] )
						/* data for this field needs quoting (string) */
						$blob2 .= ", '{$data[$i]}'";
					else
						/* data for this field is an integer or like type */
						$blob2 .= ", '{$data[$i]}'";
			}
			
			$blob1 = ltrim($blob1, ', ');
			$blob2 = ltrim($blob2, ', ');
			
			$result = do_mysql_query("insert into `$table` ($blob1) values ($blob2)");
		}
		fclose($source);
		
		return $result;
		
	} /* end of massive if/else that branches this function */
}



/**
 * Wrapper around mysql_insert_id, to keep all the process-resource access in this library.
 */
function get_mysql_insert_id()
{
	global $mysql_link;
	return mysql_insert_id($mysql_link);
}


/**
 * Gets the size in bytes of data plus indexes for a named MySQL table.
 * 
 * If $table contains a "%", then the sum of the sizes of all matching tables are returned
 * (as "like" is used rather than "=" or "regexp"). 
 */
function get_mysql_table_size($table)
{
	global $Config;
	
	$table = mysql_real_escape_string($table);
	
	$sql = "select sum(INDEX_LENGTH), sum(DATA_LENGTH) from information_schema.TABLES 
				where TABLE_SCHEMA='{$Config->mysql_schema}' and TABLE_NAME like '$table'";
	
	return array_sum(mysql_fetch_row(do_mysql_query($sql)));
}




/* 
 * the next two functions are really just for convenience.
 * 
 * Note also, they have no effect on InnoDB tables
 * (which nowadays, unlike in the early days of CQPweb, are default).
 */

/** Turn off indexing for a given MySQL table. */
function database_disable_keys($table)
{
	do_mysql_query("alter table " . mysql_real_escape_string($table) . " disable keys");
}
/** Turn on indexing for a given MySQL table. */
function database_enable_keys($table)
{
	do_mysql_query("alter table " . mysql_real_escape_string($table) . " enable keys");
}


/**
 * Gets the MySQL string identifier of the collation to be used by dynamic databases
 * built for a particular corpus.
 * 
 * Argument is a corpus object (from the DB).
 */
function deduce_corpus_mysql_collation($corpus_info)
{
	/* this encapsulates collation setup, so when we use more than just these 2,
	 * we can simply set matters up here, and everything else should cascade.
	 */
	return $corpus_info->uses_case_sensitivity ? 'utf8_bin' : 'utf8_general_ci' ;
} 






/**
 * Prints a debug message. 
 * 
 * Messages are not printed if the config variable $print_debug_messages is not set to
 * true.
 * 
 * (Currently, this function just wraps pre_echo, or echoes naked to the command line
 * - but we might want to create a more HTML-table-friendly version later.)
 */
function print_debug_message($message)
{
	global $Config;
	
	if ($Config->print_debug_messages)
	{
		if ($Config->debug_messages_textonly)
			echo $message. "\n\n";
		else
			pre_echo($message);
	}
}


/**
 * Echoes a string, but with HTML 'pre' tags (ideal for debug messages).
 */
function pre_echo($s)
{
	echo "\n\n<pre>\n", escape_html($s), "\n</pre>\n";
}


/** 
 * This function removes any existing start/end anchors from a regex
 * and adds new ones.
 */
function regex_add_anchors($s)
{
	$s = preg_replace('/^\^/',     '', $s);
	$s = preg_replace('/^\\A/',    '', $s);
	$s = preg_replace('/\$$/',     '', $s);
	$s = preg_replace('/\\[Zz]$/', '', $s);
	return "^$s\$";
}




/**
 * Wrapper for htmlspecialchars($string, ENT_COMPAT, 'UTF-8', false) 
 * -- these being the settings we want almost everywhere in CQPweb.
 */
function escape_html($string)
{
	return htmlspecialchars($string, ENT_COMPAT, 'UTF-8', false);
} 


/**
 * Removes any nonhandle characters from a string.
 *  
 * A "handle" can only contain ascii letters, numbers, and underscore.
 * 
 * If removing the nonhandle characters reduces it to an
 * empty string, then it will be converted to "__HANDLE".
 * 
 * (Other code must be responsible for making sure the handle is unique
 * where necessary.)
 * 
 * A maximum length can also be enforced if the second parameter
 * is set to greater than 0.
 */
function cqpweb_handle_enforce($string, $maxlength = -1)
{
	$handle = preg_replace('/[^a-zA-Z0-9_]/', '', $string);
	if (empty($handle))
		$handle = '__HANDLE';
	return ($maxlength < 1 ? $handle : substr($handle, 0, $maxlength) );
}

/**
 * Checks a string for validity as a handle.
 * 
 * Returns true iff the argument string is OK as a handle,
 * that is, iff there are no non-word characters (i.e. no \W)
 * in the string and it is not empty.
 * 
 * A maximum length can also be checked if the second parameter
 * is set to greater than 0.
 */
function cqpweb_handle_check($string, $maxlength = -1)
{
	return (
			is_string($string)
			&&   $string !== ''
			&&   1 > preg_match('/\W/', $string) 
			&&   ( $maxlength < 1 || strlen($string) <= $maxlength )
			);
}


/**
 * Function which performs standard safety checks on a qname parameter in
 * the global $_GET array, and exits the program if it is either (a) not present
 * or (b) not a word-character-only string.
 * 
 * The return value is then safe from XSS if embedded into HTML output;
 * and is also safe for embedding into MySQL queries.
 * 
 * A named index into $_GET can be supplied; if none is, "qname" is assumed.
 */
function safe_qname_from_get($index = 'qname')
{
	if (!isset($_GET[$index]))
		exiterror('No query ID was specified!');
	else
		$qname = $_GET[$index];
	if (! cqpweb_handle_check($qname))
		exiterror('The specified query ID is badly formed!');
	return $qname;
}

/** 
 * Support function for processing parameters for functions in metadata.inc.php/xml.inc.php.
 * 
 * Checks a "$corpus" (corpus SQL handle) parameter. If the value is NULL, returns
 * the name from the global corpus object (or errors out if there isn't one). If the
 * value is not NULL, it runs it through the SQL string escape and returns it.
 * 
 * This allows the following dual parameter format.
 * 
 * OLD -- no corpus parameter, always uses the global variable.
 * NEW -- allows a corpus handle to be passed in (and real-escapes it).
 * 
 * Longterm, all functions that use this should be forced to avoid use of global $Corpus.
 */ 
function safe_specified_or_global_corpus($corpus_to_check)
{
	if (empty($corpus_to_check))
	{
		global $Corpus;
		if ($Corpus->specified)
			return $Corpus->name;
		else
			exiterror("A corpus function was called with no corpus either specified locally or globally implicit.");
	}
	else
		return mysql_real_escape_string($corpus_to_check);
}



/**
 * Sets the location field in the HTTP response
 * to an absolute location based on the supplied relative URL,
 * iff the headers have not yet been sent.
 * 
 * If, on the other hand, the headers have been sent, 
 * the function does nothing.
 * 
 * The function DOES NOT exit. Instead, it returns the
 * value it itself got from the headers_sent() function.
 * This allows the caller to check whether it needs to
 * do something alternative.
 */
function set_next_absolute_location($relative_url)
{
	if (false == ($test = headers_sent()) )
		header('Location: ' . url_absolutify($relative_url));
	return $test;
}


/**
 * This function creates absolute URLs from relative ones by adding the relative
 * URL argument $u to the real URL of the directory in which the script is running.
 * 
 * The URL of the currently-running script's containing directory is worked out  
 * in one of two ways. If the global configuration variable "$cqpweb_root_url" is
 * set, this address is taken, and the corpus handle (SQL version, IE lowercase, which 
 * is the same as the subdirectory that accesses the corpus) is added. If no SQL
 * corpus handle exists, the current script's containing directory is added to 
 * $cqpweb_root_url.
 * 
 * $u will be treated as a relative address  (as explained above) if it does not 
 * begin with "http:" or "https:" and as an absolute address if it does.
 * 
 * Note, this "absolute" in the sense of having a server specified at the start, 
 * it can still contain relativising elements such as '/../' etc.
 */
function url_absolutify($u, $special_subdir = NULL)
{
	global $Config;
	global $Corpus;
	
	/* outside a corpus, extract the immediate containing directory from REQUEST_URI (e.g. 'adm') */
	if (empty($special_subdir) && (empty($Corpus) || !$Corpus->specified) )
	{
		preg_match('|\A.*/(\w+)/[^/]*\z|', $_SERVER['REQUEST_URI'], $m);
		$special_subdir = $m[1];
	}

	if (preg_match('/\Ahttps?:/', $u))
		/* address is already absolute */
		return $u;
	else
	{
		/* 
		 * make address absolute by adding server of this script plus folder path of this URI;
		 * this may not be foolproof, because it assumes that the path will always lead to the 
		 * folder in which the current php script is located -- but should work for most cases 
		 */
		if (empty($Config->cqpweb_root_url))
			$url = (isset ($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 'https://' : 'http://')
				  /* host name */
				. $_SERVER['HTTP_HOST']
				  /* path from request URI excluding filename */ 
				. preg_replace('|/[^/]*\z|', '/', $_SERVER['REQUEST_URI'])
				  /* target path relative to current folder */ 
				. $u;
		else
			$url = $Config->cqpweb_root_url 
				. ( 
					(!empty($Corpus->name)) 
					/* within a corpus, use the root + the corpus sql name */
					? $Corpus->name  
					: $special_subdir
				)
				. '/' . $u
		;
		
		/* attempt to resolve ../ if present */
		$url = preg_replace('|/[^\./]+/\.\./|', '/', $url);
		
		return $url; 
	}
}



/** 
 * Checks whether the current script has $_GET['uT'] == "y" 
 * (&uT=y is the terminating element of all valid CQPweb URIs).
 * 
 * "uT" is short for "urlTest", by the way.
 */
function url_string_is_valid()
{
	if ( (!isset($_GET['uT'])) && isset($_POST['uT']))
		return ($_POST['uT'] == 'y');
	return (isset($_GET['uT']) && $_GET['uT'] == 'y');
}




/**
 * Returns a string of "var=val&var=val&var=val".
 * 
 * $changes = array of arrays, 
 * where each array consists of [0] a field name  
 *                            & [1] the new value.
 * 
 * If [1] is an empty string, that pair is not included.
 * 
 * WARNING: adds values that weren't already there at the START of the string.
 * 
 */
function url_printget($changes = "Nope!")
{
	$change_me = is_array($changes);

	$string = '';
	foreach ($_GET as $key => $val)
	{
		if (!empty($string))
			$string .= '&';

		if ($change_me)
		{
			$newval = $val;

			foreach ($changes as &$c)
				if ($key == $c[0])
				{
					$newval = $c[1];
					$c[0] = '';
				}
			/* only add the new value if the change array DID NOT contain a zero-length string */
			/* otherwise remove the last-added & */
			if ($newval != "")
				$string .= $key . '=' . urlencode($newval);
			else
				$string = preg_replace('/&\z/', '', $string);
				
		}
		else
			$string .= $key . '=' . urlencode($val);
		/* urlencode needed here since $_GET appears to be un-makesafed automatically */
	}
	if ($change_me)
	{
		$extra = '';
		foreach ($changes as &$c)
			if ($c[0] != '' && $c[1] != '')
				$extra .= $c[0] . '=' . $c[1] . '&';
		$string = $extra . $string;
	}
	
	return $string;
}


/**
 * Clears an HTTP parameter from the global array ($_GET, also $_POST) *and* any other plaace a script might look for it
 * (currently: $_SERVER['QUERY_STRING'] - there may be other places, but those are the only ones currently used by CQPweb.
 * Purpose: to allow us to easily make sure a "used-up" parameter can never be found & passed on. 
 *   
 * @param string $key   Key of parameter to clear from the variables representing HTTP request.
 */
function clear_http_parameter($key)
{
	unset($_GET[$key], $_POST[$key]);
	do 
	{
		$count = 0;
		$_SERVER['QUERY_STRING'] 
			= preg_replace_callback(
				"/([&?])$key=[^&]*(&?)/", 
				function ($m) 
				{
					if ($m[1] == '?')
						return '?';
					else
						return ( empty($m[2]) ? '' : '&' );
				},
				$_SERVER['QUERY_STRING'],
				-1,
				$count
			);
	} while ($count > 0);
}


/**
 * Returns a string of "&lt;input type="hidden" name="key" value="value" /&gt;..."
 * 
 * $changes = array of arrays, 
 * where each array consists of [0] a field name  
 *                            & [1] the new value.
 * 
 * If [1] is an empty string, that pair is not included.
 *  
 * WARNING: adds values that weren't there at the START of the string.
 */
function url_printinputs($changes = "Nope!")
{
	$change_me = is_array($changes);

	$string = '';
	foreach ($_GET as $key => $val)
	{
		if ($change_me)
		{
			$newval = $val;
			foreach ($changes as &$c)
				if ($key == $c[0])
				{
					$newval = $c[1];
					$c[0] = '';
				}
			/* only add the new value if the change array DID NOT contain a zero-length string */
			if ($newval !== '')
				$string .= '<input type="hidden" name="' . $key . '" value="' . escape_html($newval) . '" />';
		}
		else
			$string .= '<input type="hidden" name="' . $key . '" value="' . escape_html($val) . '" />';
	}

	if ($change_me)
	{
		$extra = '';
		foreach ($changes as &$c)
			if ($c[0] !== '' && $c[1] !== '')
				$extra .= '<input type="hidden" name="' . $c[0] . '" value="' . escape_html($c[1]) . '" />';
		$string = $extra . $string;
	}
	return $string;
}



/**
 * Gets an integer variable indicating how many "entries" (of whatever)
 * are to be shown per page.
 * 
 * In the concordance display, can also return "all" or "count" as these 
 * are special codes for that display.
 * 
 * If an invalid value is given as $pp, this will cause CQPweb to default 
 * back to $Config->default_per_page.
 * 
 * @param $pp  A "per page" value from $_GET to be validated.  
 */
function prepare_per_page($pp)
{	
	global $Config;
	
	if ( is_string($pp) )
		$pp = strtolower($pp);
		/* in order to accept 'ALL' and 'COUNT'. */
	
	switch($pp)
	{
	/* extra values valid in concordance.php */
	case 'count':
	case 'all':
		if (strpos($_SERVER['PHP_SELF'], 'concordance.php') !== false)
			;
		else
			$pp = $Config->default_per_page;
		break;

	default:
		if (is_numeric($pp))
		{
			settype($pp, 'integer');
			/* must be positive */
			if (1 > $pp)
				$pp = $Config->default_per_page;
		}	
		else
			$pp = $Config->default_per_page;
			/* this also catches the case where the parameter is NULL or an unset var */
		break;
	}
	return $pp;
}


function prepare_page_no($n)
{
	if (is_numeric($n))
	{
		settype($n, 'integer');
		return $n;
	}
	else
		return 1;
}




/**
 * Returns a bool: is the specified user a username?
 */
function user_is_superuser($username)
{
	return in_array($username, list_superusers());
}


/**
 * Returns an array of superuser usernames.
 */
function list_superusers()
{
	/* superusers are determined in the config file */
	global $Config;
	
	static $a = NULL;
	
	if (empty($a))
		$a = explode('|', $Config->superuser_username);
	
	return $a;
}



/**
 * Change the character encoding of a specified text file. 
 * 
 * The re-coded file is saved to the path of $outfile.
 * 
 * Infile and outfile paths cannot be the same.
 */
function change_file_encoding($infile, $outfile, $source_charset_for_iconv, $dest_charset_for_iconv)
{
	if (! is_readable($infile) )
		exiterror_arguments($infile, "This file is not readable.");
	$source = fopen($infile, 'r');

	if (! is_writable(dirname($outfile)) )
		exiterror_arguments($outfile, "This path is not writable.");
	$dest = fopen($outfile,  'w');
	
	while (false !== ($line = fgets($source)) )
		fputs($dest, iconv($source_charset_for_iconv, $dest_charset_for_iconv, $line));
	
	fclose($source);
	fclose($dest);
}




function php_execute_time_unlimit($switch_to_unlimited = true)
{
	static $orig_limit = 30;

	if ($switch_to_unlimited)
	{
		$orig_limit = (int)ini_get('max_execution_time');
		set_time_limit(0);
	}
	else
	{
		set_time_limit($orig_limit);
	}
}

function php_execute_time_relimit()
{
	php_execute_time_unlimit(false);
}


/** 
 * Call as show_var($x, get_defined_vars());
 * 
 * Omit 2nd arg in global scope.
 * 
 * THIS IS A DEBUG FUNCTION. 
 */
function show_var(&$var, $scope=false, $prefix='unique', $suffix='value')
{
	$vals = (is_array($scope) ? $scope : $GLOBALS);

	$old = $var;
	$var = $new = $prefix.mt_rand().$suffix;
	$vname = false;
	foreach($vals as $key => $val) 
		if($val === $new) 
			$vname = $key;
	$var = $old;

	echo "\n<pre>-->\$$vname<--\n";
	var_dump($var);
	echo "</pre>";
}

/** THIS IS A DEBUG FUNCTION */
function dump_mysql_result($result)
{
	$s = '<table class="concordtable"><tr>';
	$n = mysql_num_fields($result);
	for ( $i = 0 ; $i < $n ; $i++ )
		$s .= "<th class='concordtable'>" 
			. mysql_field_name($result, $i)
			. "</th>";
	$s .=  '</tr>
		';
	
	while ( ($r = mysql_fetch_row($result)) !== false )
	{
		$s .= '<tr>';
		foreach($r as $c)
			$s .= "<td class='concordgeneral'>$c</td>\n";
		$s .= '</tr>
			';
	}
	$s .= '</table>';
	
	return $s;
}





/**
 * Convenience function to delete a specified directory, plus everything in it.
 */
function recursive_delete_directory($path)
{		
	if (!is_dir($path))
		return;
	
	foreach(scandir($path) as $f)
	{
		/* delete directories; unlink regualr files and symlinks. */
		if ($f == '.' || $f == '..')
			;
		else if (is_dir("$path/$f") && !is_link("$path/$f"))
			recursive_delete_directory("$path/$f");
		else
			unlink("$path/$f");
	}
	rmdir($path);
}

/**
 * Convenience function to count the size of all files in a directory, recursively.
 * 
 * @return int  The size in bytes.
 */
function recursive_sizeof_directory($path)
{
	if (!is_dir($path))
		return 0;

	$size = 0;
	
	foreach(scandir($path) as $f)
	{
		if ($f == '.' || $f == '..')
			;
		else if (is_dir("$path/$f"))
			$size += recursive_sizeof_directory("$path/$f");
		else
			$size += filesize("$path/$f");
	}
	
	return $size;
}


/**
 * Convenience function to recursively copy a directory.
 * 
 * Both $from and $to should be directory paths. 
 * 
 * If $from is a file or symlink rather than a directory, 
 * we default back to the behaviour
 * of php's builtin copy() function.
 * 
 * If $to already exists, it will be overwritten.
 */
function recursive_copy_directory($from, $to)
{
	if (is_dir($from))
	{
		recursive_delete_directory($to);
		mkdir($to);
		
		foreach(scandir($from) as $f)
		{
			if ($f == '.' || $f == '..')
				;
			else if (is_dir("$from/$f"))
				recursive_copy_directory("$from/$f", "$to/$f");
			else
				copy("$from/$f", "$to/$f");
		}
	}
	else
		copy($from, $to);
}



/**
 * This function stores values in a table that would be too big to send via GET.
 *
 * Instead, they are referenced in the web form by their id code (which is passed 
 * by get) and retrieved by the script that processes the user input.
 * 
 * The return value is the id code that you should use in the web form.
 * 
 * Things stored in the longvalues table are deleted when they are 5 days old.
 * 
 * The retrieval function is longvalue_retrieve().
 *  
 */
function longvalue_store($value)
{
	global $Config;	
	// TODO do not use instance name, as there might be more than one longvalue per CQPweb run-instance.
	// use something based on instance name but guarantee its uniqueness.
	
	/* clear out old longvalues */
	do_mysql_query("delete from system_longvalues where timestamp < DATE_SUB(NOW(), INTERVAL 5 DAY)");
	
	$value = mysql_real_escape_string($value);
	
	do_mysql_query("insert into system_longvalues (id, value) values ('{$Config->instance_name}', '$value')");

	return $Config->instance_name;
}


/**
 * Retrieval function for values stored with longvalue_store.
 */
function longvalue_retrieve($id)
{	
	$id = mysql_real_escape_string($id);
	
	$result = do_mysql_query("select value from system_longvalues where id = '$id'");
	
	$r = mysql_fetch_row($result);
		
	return $r[0];
}


/**
 * Send an email with appropriate CQPweb boilerplate, plus error checking. 
 * 
 * @param string $address_to     The "send" email address. Can be a raw address or a name plus address in < ... >.
 * @param string $mail_subject   Subject line.
 * @param string $mail_content   The email body.
 * @param array  $extra_headers  Array of extra header lines (one per entry, no line breaks). If these DO NOT
 *                               include From: / Reply To:, then (if available) the system's email address
 *                               (specified in config file) will be used instead.
 * @return bool                  True if email sent, otherwise false.
 */
function send_cqpweb_email($address_to, $mail_subject, $mail_content, $extra_headers = array())
{
	global $Config;
	
	if ($Config->cqpweb_no_internet)
		return false;

	if (!empty($Config->cqpweb_root_url))
		$mail_content .= "\n" . $Config->cqpweb_root_url . "\n";
	
	if (!empty($Config->cqpweb_email_from_address))
	{
		$add_from = true;
		$add_reply_to = true;
		
		foreach($extra_headers as $h)
		{
			$lch = strtolower($h);
			if (substr($lch,0,5) == 'from:')
				$add_from = false;
			if (substr($lch,0,9) == 'reply-to:')
				$add_reply_to = false;
		}
		
		if ($add_from)
			$extra_headers[] = "From: {$Config->cqpweb_email_from_address}";
		if ($add_reply_to)
			$extra_headers[] = "Reply-To: {$Config->cqpweb_email_from_address}";
	}

	return (bool)mail($address_to, $mail_subject, $mail_content, implode("\r\n", $extra_headers));	
}


/**
 * Perform Bonferroni or Šidák correction.
 * 
 * NB this file may not be a good place to do have this function, long-run.
 */ 
function correct_alpha_for_familywise($alpha, $n_comparisons, $type = 'Bonferroni')
{
	/* any empty value signifies don't correct */
	if (empty($type))
		return $alpha;
	
	switch($type)
	{
		case 'Bonferroni':
			return $alpha/$n_comparisons;
			
		case 'Šidák':
		case 'Sidak':
			return 1.0 - pow((1.0 - $alpha), 1.0/$n_comparisons);
			
		default:
			exiterror_general("Unrecognised correction for multiple comparisons.");
	}
}


// TODO move these to plugins.inc.php?

/** Returns an object from the plugin registry, or else false if not found. */
function retrieve_plugin_info($class)
{
	//TODO IO don't think this is global any mroe
	global $plugin_registry;
	if (!empty($plugin_registry))
		foreach ($plugin_registry as $p)
			if ($p->class == $class)
				return $p;
	return false;
}


/** Returns a list of the available plugins (array of objects from the global registry) of the specified type. */ 
function list_plugins_of_type($type)
{
	//TODO IO don't think this is global any mroe
	global $plugin_registry;
	$result = array();
	if (!empty($plugin_registry))
		foreach ($plugin_registry as $p)
			if ($p->type & $type)
				$result[] = $p;
	return $result;
}



/*
 * REFLECTION functions. This is not reflection in the technical sense, 
 * but it's similar, so we can use the name to make these funcitons distinctive.
 * 
 * Each of these funcs returns some blob of info about the composition of the system
 * - e.g. a list of required code files from some part of the system.
 * 
 * The functions can be used by any part of CQPweb that needs to inspect its own content
 * for whatever reason. 
 */

/**
 * Return array of builtin javascript files in the ../jsc directory
 * (to distinguish such code files from any manually-added javascript files
 * which might be used in XML visualisation; or in future, perhaps, for other purposes). 
 */
function get_jsc_reflection_list()
{
	return array (
			'always.js',          
			'analyse-md.js',      
			'attribute-embiggen.js',  
			'captcha.js',             
			'colloc-options.js',
			'corpus-name-highlight.js',
			'cword.js',           
			'distTableSort.js',   
			'jquery.js',              
			'metadata-embiggen.js',   
			'misc.js',
			'modal.js',
			'queryhome.js',       
			'textmeta.js',            
			'user-quicksearch.js',
			'wz_tooltip.js',
		);
	/* note the above needs to be updated whenever anything in the ../jsc code is reorganised / extended. */
}

/**
 * Return array of .css files in the ../css directory that have filenames 
 * that were created by CQPweb rather than being manually added by the admin.
 */
function get_css_reflection_list()
{
	return array (
			'CQPweb.css',
			'CQPweb-aqua.css',
			'CQPweb-brown.css',
			'CQPweb-darkblue.css',
			'CQPweb-dusk.css',
			'CQPweb-gold.css',
			'CQPweb-green.css',
			'CQPweb-lime.css',
			'CQPweb-neon.css',
			'CQPweb-purple.css',
			'CQPweb-red.css',
			'CQPweb-rose.css',
			'CQPweb-teal.css',
			'CQPweb-yellow.css',
			'CQPweb-user-monochrome.css',
		);
	/* note the above needs to be updated whenever new builtin colours are added. */
}

