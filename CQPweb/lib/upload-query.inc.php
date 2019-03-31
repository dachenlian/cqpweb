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
 * @file
 * 
 * This file contains the script for uploading query files.
 */


/* Allow for usr/xxxx/corpus: if we are 3 levels down instead of 2, move up two levels in the directory tree */
if (! is_dir('../lib'))
	chdir('../../../exe');



require('../lib/environment.inc.php');

require('../lib/user-lib.inc.php');
require('../lib/cqp.inc.php');
require('../lib/cache.inc.php');
require('../lib/subcorpus.inc.php');
require('../lib/library.inc.php');
require('../lib/uploads.inc.php');
require('../lib/exiterror.inc.php');
require('../lib/html-lib.inc.php');

cqpweb_startup_environment();


/*
 * Note that when there are more types of upload than query upload (e.g. file for user corpus insertion)
 * this script may become "upload-admin", with many different actions controlled by a switch.
 * 
 * TODO
 * 
 * An obvious first one to do: upload arbitrary subcorpus
 */


/* ----------------------------------------- *
 * check that we have the parameters we need * 
 * ----------------------------------------- */



/* do we have the save name? */

if (isset($_GET["uploadQuerySaveName"]))
	$save_name = $_GET["uploadQuerySaveName"];
else
	exiterror_parameter('No save name was specified!');


/* do we have the array of the uploaded file? */

$filekey = "uploadQueryFile";

if (! (isset($_FILES[$filekey]) && is_array($_FILES[$filekey])) )
	exiterror_parameter('Information on the uploaded file was not found!');


/* did the upload actually work? */

assert_successful_upload($filekey);
assert_upload_within_user_permission($filekey);


/* did the user attempt a binary upload? */

if (isset($_GET["uploadBinary"]))
{
	$binary_upload = (bool) $_GET["uploadBinary"];
	
	if ($binary_upload && ! $User->has_cqp_binary_privilege())
		exiterror('Your account lacks the necessary permissions to insert binary files into the system.');
}
else
	$binary_upload = false;


/* ------------------- *
 * check the save name *
 * ------------------- */


/* is it a handle? */
if (! cqpweb_handle_check($save_name) )
	exiterror(array(
					'Names for saved queries can only contain letters, numbers and the underscore character (&nbsp;_&nbsp;)!',
					'Please use the BACK-button of your browser and change your input accordingly.'
					) );

/* Does a query by that name already exist for (this user + this corpus) ? */
if ( save_name_in_use($save_name) )
	exiterror(array(
					/* note, it's safe to echo back without XSS risk, because we know it is handle at this point */
					"A saved query with the name ``$save_name'' already exists.",
					'Please use the BACK-button of your browser and change your input accordingly.'
					) );	

/* we're satisfied: ergo, generate our qname for use below. */

$qname = qname_unique($Config->instance_name);






if (! $binary_upload)
{
	/*
	 * =========================================
	 * It's a standard upload of the undump kind
	 * =========================================
	 */
	
// 	/* get the filepath of the uploaded file */
// 	$uploaded_file = uploaded_file_to_upload_area($_FILES[$filekey]["name"], 
// 	                                              $_FILES[$filekey]["type"],
// 	                                              $_FILES[$filekey]["size"],
// 	                                              $_FILES[$filekey]["tmp_name"],
// 	                                              $_FILES[$filekey]["error"],
// 	                                              true
// 	                                              );
	/* get the filepath of the uploaded file */
	$uploaded_file = uploaded_file_to_upload_area($filekey, true );
	//TODO why not just copy ONCE??? read the tmp_file into a location in the upload area below, in the call to uploaded_file_guarantee_dump
	
	
	/* determine the filepath we want to put it in for undumping */
	$undump_file = $uploaded_file;
	while (file_exists($undump_file ))
		$undump_file .= '_';
	
	/* guarantee that the format is good */
	
	$count = NULL;
	$line  = NULL;
	
	$hits = uploaded_file_guarantee_dump($uploaded_file, $undump_file, $count, $line);
	
	/* unconditional, success or failure... */
	unlink($uploaded_file);
	
	if (!$hits)
		exiterror(array(
			'Your uploaded file has a format error.',
			'The file must only consist of two columns of numbers (separated by a tab-stop).',
			'The error was encountered at line ' . $count . '. The incorrect line is as follows:',
			"   $line   ",
			'Please amend your query file and retry the upload.'
		));

	
	/* undump to CQP as a new query, and save */
	$cqp->execute("undump $qname < '$undump_file'");
	$cqp->execute("save $qname");
	
	/* delete the format-guaranteed uploaded file */
	unlink($undump_file);
	

}
else
{
	/*
	 * ====================================
	 * It's a binary-data file reinsertion.
	 * ====================================
	 */
	
	/* apply a check for the correct CQP format. */
	if (!cqp_file_check_format($_FILES[$filekey]['tmp_name'], true, true, true))
		exiterror('The file you uploaded was not in the correct format for a CQP binary query data file on this system.');
	
	/* this is our target path... */
	$target_path = $Config->dir->cache . '/' . $Corpus->cqp_name . ':' . $qname; 
	if (file_exists($target_path))
		exiterror("Critical error - cannot overwrite existing cache file via binary reinsertion. ");
	
	/* move uploaded file to the cache directory under that new qname. */
	if (move_uploaded_file($_FILES[$filekey]['tmp_name'], $target_path)) 
		chmod($new_path, 0664);
	else
		exiterror("Critical error - reinserting binary file in CQPweb's data store failed.");
	
	/* CQP then needs to refresh the Datadir. After which, re-set the corpus. */
	$cqp->execute("set DataDirectory '{$Config->dir->cache}'");
	$cqp->set_corpus($Corpus->cqp_name);
	
	$hits = $cqp->querysize($qname);
	if (1 > $hits)
	{
		unlink($target_path);
		exiterror("CQP could not interpret your uploaded file.");
	}
}


/*
 * ========================================================
 * Record creation actions, common to both types of upload.
 * ========================================================
 */

/* work out how many texts have hits (for the DB record) */
$num_of_texts = count( $cqp->execute("group $qname match text_id") );

/* put the query into the saved queries DB */
$cache_record = QueryRecord::create(
		$qname, 
		$User->username, 
		$Corpus->name, 
		'uploaded', 
		'', 
		'', 
		QueryScope::new_by_unserialise(""),
		$hits, 
		$num_of_texts, 
		"upload[{$Config->instance_name}]"
		);
$cache_record->saved = CACHE_STATUS_SAVED_BY_USER;
$cache_record->save_name = $save_name;
$cache_record->save();


/* and let's finish, assuming all succeeded, by redirecting ... */
set_next_absolute_location('index.php?thisQ=savedQs&uT=y');

cqpweb_shutdown_environment();

