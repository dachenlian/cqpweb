<?php
/*
 * CQPweb: a user-friendly interface to the IMS Corpus Query Processor
 * Copyright (C) 2008-today Andrew Hardie and contributors
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
 * @file  Functions for dealing with aspects of the upload process that may be reusable.
 */



/**
 * Aborts the script with an error message iff the file upload was unsuccessful for any reason.
 * 
 * @param string $file_input_name  Key into PHP's _FILES array that corresponds to this file 
 *                                 (same as the name of the file-input element in the form that 
 *                                 generated the upload).
 */
function assert_successful_upload($file_input_name)
{
	/* Check for upload errors; convert back to int: execute.inc.php may have turned it to a string */
	switch ($error_code = (int)$_FILES[$file_input_name]['error'])
	{
	case UPLOAD_ERR_OK:
		return;
	case UPLOAD_ERR_INI_SIZE:
		exiterror('That file is too big to upload due to system settings! Contact your system administrator.');
	case UPLOAD_ERR_FORM_SIZE:
		exiterror('That file is too big to upload due to webpage settings! Contact your system administrator.');
	case UPLOAD_ERR_PARTIAL:
		exiterror('Only part of the file you tried to upload was received! Please try again.');
	case UPLOAD_ERR_NO_FILE:
		exiterror('No file was uploaded! Please try again.');
	case UPLOAD_ERR_NO_TMP_DIR:
		exiterror('Could not find temporary folder for the upload! Contact your system administrator.');
	case UPLOAD_ERR_CANT_WRITE:
		exiterror('Writing to disk failed during upload! Please try again.');
	default:
		exiterror('The file did not upload correctly (for an unknown reason)! Please try again.');
	}
}


/**
 * Aborts the script with an error message iff the file upload is too big for the current user's permissions.
 * 
 * @param string $file_input_name  Key into PHP's _FILES array that corresponds to this file 
 *                                 (same as the name of the file-input element in the form that 
 *                                 generated the upload).
 */
function assert_upload_within_user_permission($file_input_name)
{
	global $User;
	
	if (!$User->is_admin())
		if ((int)$_FILES[$file_input_name]['size'] > $User->max_upload_file())
			exiterror('You do not have the necessary permissions to upload a file of this size to CQPweb.');
}



//  * @param string $original_name  The name from the client machine:       normally $_FILES[$name]['name'].
//  * @param string $file_type      The file type (MIME, if present):       normally $_FILES[$name]['type'].
//  * @param int    $file_size      The file size in bytes:                 normally $_FILES[$name]['size'].
//  * @param string $temp_path      The location it was uploaded to:        normally $_FILES[$name]['tmp_name'].
//  * @param int    $error_code     The error code from the upload process: normally $_FILES[$name]['error'].
/**
 * Puts an uploaded file (of whatever kind...) into the upload area.
 * 
 * Some of the parameters are not used, but are passed through in case later changes need them. 
 * 
 * Returns an absolute path to the new file. The name of the new file may have been extended by "_"
 * if necessary to avoid a clash with an existing file.
 * 
 * @param string $file_input_name  Key into PHP's _FILES array that corresponds to this file 
 *                                 (same as the name of the file-input element in the form that 
 *                                 generated the upload).
 * @param bool   $user_upload      Default false; if true, the file goes into the present user's upload folder
 *                                 rather than the main folder (which is sysadmin only).
 */
function uploaded_file_to_upload_area($file_input_name, $user_upload = false)
{
	global $Config;
	global $User;

	
// TODO change this function so it does the dirty work of accessing _FILES itself,
// so we can pass in a $file_input_name key instead of multiple different vars. 


	/* check the directory exists for user-uploaded files */
	if ($user_upload)
	{	
		if (!is_dir("{$Config->dir->upload}/usr"))
			mkdir("{$Config->dir->upload}/usr", 0775);
		if (!is_dir("{$Config->dir->upload}/usr/{$User->username}"))
			mkdir("{$Config->dir->upload}/usr/{$User->username}", 0775);
	}
	
	/* find a new name - a file that does not exist */
	for ($filename = $basic_filename = basename($_FILES[$file_input_name]['name']), $i = 0; true ; $filename = $basic_filename . '.' . ++$i)
	{
		$new_path = $Config->dir->upload . '/' . ($user_upload ? "usr/{$User->username}/" : '' ) . $filename;
		if ( ! file_exists($new_path) )
			break;
	}
	
	if (move_uploaded_file($_FILES[$file_input_name]['tmp_name'], $new_path)) 
		chmod($new_path, 0664);
	else
		exiterror("The file could not be processed! Possible file upload attack.");
	
	return $new_path;
}

/**
 * Change linebreaks in the named file in the upload area to Unix-style
 * (or, to Windows style iff the global "cqpweb_running_on_windows" variable is true).
 * 
 * Original file is overwritten.
 */
function uploaded_file_fix_linebreaks($filename)
{
	global $Config;

	$path = "{$Config->dir->upload}/$filename";
	
	if (!file_exists($path))
		exiterror_general('Your request could not be completed - that file does not exist.');
	
	$intermed_path = "{$Config->dir->upload}/____...______uploaded_file_fix_linebreaks____...____temp.___";
	
	if ($Config->cqpweb_running_on_windows)
	{
		$func = 'preg_replace';
		$search  = "/([^\r])\n$/";
		$replace = "$1\r\n";
	}
	else
	{
		$func = 'str_replace';
		$search  = "\r\n";
		$replace = "\n";
	}
	
	$source = fopen($path, 'r');
	$dest = fopen($intermed_path, 'w');
	
	/* check for initial UTF8-BOM */
	$first = fgets($source);
	if (substr($first, 0, 3) == "\xef\xbb\xbf")
		$first = substr($first, 3);
	fputs($dest, $func($search, $replace, $first));
	
	while ( false !== ($line = fgets($source)))
		fputs($dest, $func($search, $replace, $line));
	
	fclose($source);
	fclose($dest);
	
	unlink($path);
	rename($intermed_path, $path);
	chmod($path, 0664);
}


// TODO - account for files in the usr directory
function uploaded_file_delete($filename)
{	
	global $Config;

	$path = "{$Config->dir->upload}/$filename";
	
	if (!file_exists($path))
		exiterror_general('Your request could not be completed - that file does not exist.');
	
	unlink($path);
}

// TODO - account for files in the usr directory
function uploaded_file_gzip($filename)
{
	global $Config;

	$path = "{$Config->dir->upload}/$filename";
	
	if (!file_exists($path))
		exiterror('Your request could not be completed - that file does not exist.');

	$zip_path = $path . '.gz';
	
	$in_file = fopen($path, "rb");
	if (!($out_file = gzopen ($zip_path, "wb")))
		exiterror('Your request could not be completed - compressed file could not be opened.');

	php_execute_time_unlimit();
	while (!feof ($in_file)) 
	{
		$buffer = fgets($in_file, 4096);
		gzwrite($out_file, $buffer, 4096);
	}
	php_execute_time_relimit();

	fclose ($in_file);
	gzclose ($out_file);
	
	unlink($path);
	chmod($zip_path, 0664);
}


// TODO - account for files in the usr directory
function uploaded_file_gunzip($filename)
{
	global $Config;

	$path = "{$Config->dir->upload}/$filename";
	
	if (!file_exists($path))
		exiterror('Your request could not be completed - that file does not exist.');
	
	if (preg_match('/(.*)\.gz$/', $filename, $m) < 1)
		exiterror('Your request could not be completed - that file does not appear to be compressed.');

	$unzip_path = "{$Config->dir->upload}/{$m[1]}";
	
	$in_file = gzopen($path, "rb");
	$out_file = fopen($unzip_path, "wb");

	php_execute_time_unlimit();
	while (!gzeof($in_file)) 
	{
		$buffer = gzread($in_file, 4096);
		fwrite($out_file, $buffer, 4096);
	}
	php_execute_time_relimit();

	gzclose($in_file);
	fclose ($out_file);
			
	unlink($path);
	chmod($unzip_path, 0664);
}


// TODO - account for files in the usr directory
function uploaded_file_view($filename)
{
	global $Config;
	
	$path = "{$Config->dir->upload}/$filename";

	if (!file_exists($path))
		exiterror('Your request could not be completed - that file does not exist.');

	$fh = fopen($path, 'r');
	
	$bytes_counted = 0;
	$data = '';
	
	while ((!feof($fh)) && $bytes_counted <= $Config->uploaded_file_bytes_to_show)
	{
		$line = fgets($fh, 4096);
		$data .= $line;
		$bytes_counted += strlen($line);
	}

	fclose($fh);
	
	$data = escape_html($data);
	
	/*
	 * Note, it is purposeful that we are not using the write HTML function(s),
	 * because the idea is to keep the HTML very simple (no JavaScript, etc.). 
	 */
	header('Content-Type: text/html; charset=utf-8');
	?>
	<html>
		<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title>CQPweb: viewing uploaded file</title>
		</head>
		<body>
			<h1>Viewing uploaded file <i><?php echo $filename;?></i></h1>
			<p>NB: for very long files only the first 100K is shown
			<hr/>
			<pre>
			<?php echo "\n" . $data; ?>
			</pre>
		</body>
	</html>
	<?php
	// TODO shouldn't this be shutdown-environment? OR better, nothing? 
	// (So that the caller can shut down?
	exit();
}

/**
 * Test a file (usually one uploaded by a user, thus the name) for compatibility with dumpfile format.
 * Writes the resulting file to a specified location (i.e. a second path).
 * 
 * Also corrects line breaks (skips empty lines, deals with CR/LF) while we're at it. 
 * Thus why we write while reading!
 * 
 * If there is an error, false is returned, and (a) the error line number is written to the 3rd argument,
 * (b) the content of the error line is written to the fourth argument. 
 * 
 * In this case, the part-complete output file is deleted.
 * 
 * @param  string $path_from   Full path of the file to read from.
 * @param  string $path_to     Full path of the file to write to. Overwrites without checking.
 * @param  int    $err_line_n  Out-parameter: Number of the line where an error is encountered,
 *                             if one is (otherwise not overwritten).
 * @param  string $err_line_s  Out-parameter: Content of the line where an error is encountered,
 *                             if one is (otherwise not overwritten).
 * @return int                 Number of valid lines in the file; OR, boolean false
 *                             if a non-valid line was encountered.
 */
function uploaded_file_guarantee_dump($path_from, $path_to, &$err_line_n, &$err_line_s)
{
	$source = fopen($path_from, 'r');
	$dest   = fopen($path_to,   'w');
	$count  = 0;
	$hits   = 0;

	/* incremetally copy the file and check its format: every line two \d+ with tabs */
	while (false !== ($line = fgets($source)))
	{
		$count++;
		
		/* do what tidyup we can, to reduce errors */
		$line = rtrim($line);
		if (empty($line))
			continue;
		
		if ( ! (0 < preg_match('/\A\d+\t\d+\z/', $line)) )
		{
			/* error detected */
			fclose($source);
			fclose($dest);
			unlink($path_to);
			$err_line_n = $count;
			$err_line_s = $line;
			return false;
		}
		
		/* target the native line break format (which is what this computer's CWB will expect.) */
		fputs($dest, $line . PHP_EOL);
		$hits++;
	}

	fclose($source);
	fclose($dest);
	
	return $hits;
}


