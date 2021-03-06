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
 * This file contains the code for calculating and showing distribution of hits across text cats.
 */

/* Allow for usr/xxxx/corpus: if we are 3 levels down instead of 2, move up two levels in the directory tree */
if (! is_dir('../lib'))
	chdir('../../../exe');

require('../lib/environment.inc.php');


/* include function library files */
require("../lib/library.inc.php");
require("../lib/html-lib.inc.php");
require("../lib/concordance-lib.inc.php");
require("../lib/concordance-post.inc.php");
require("../lib/exiterror.inc.php");
require("../lib/user-lib.inc.php");
require("../lib/metadata.inc.php");
require("../lib/subcorpus.inc.php");
require("../lib/cache.inc.php");
require("../lib/xml.inc.php");
require("../lib/db.inc.php");
require("../lib/cqp.inc.php");
require("../lib/distribution-lib.inc.php");



cqpweb_startup_environment(CQPWEB_STARTUP_DONT_CONNECT_CQP);




/* Global variables: the qname, the qrecord, the dbname, the dbrecord, and finally the distinfo (containing all info for this program run.) */


$qname = safe_qname_from_get();

$query_record = QueryRecord::new_from_qname($qname);
if (false === $query_record)
	exiterror_general("The specified query $qname was not found in cache!");


/* does a db for the distribution exist? */

/* search the db list for a db whose parameters match those of the query named as qname; if it doesn't exist, create one */

$db_record = check_dblist_parameters(new DbType(DB_TYPE_DIST), $query_record->cqp_query, $query_record->query_scope, $query_record->postprocess);

if (false === $db_record)
{
	$dbname = create_db(new DbType(DB_TYPE_DIST), $qname, $query_record->cqp_query, $query_record->query_scope, $query_record->postprocess);
	$db_record = check_dblist_dbname($dbname);
}
else
{
	$dbname = $db_record['dbname'];
	touch_db($dbname);
}


/* we tuck all the program info into a single object that can be passed as a unit. */
$dist_info = new DistInfo($_GET, $query_record);

$dist_info->append_db_record($db_record);
$dist_info->append_query_record($query_record);



/* OK, we should now be ready to go. */

if (DistInfo::PROG_DOWNLOAD == $dist_info->program)
{
	/* the easy case: we want to d the download. */
	do_distribution_plaintext_download($dist_info);
	
}
else 
{
	/* begin HTML output */
	echo print_html_header($Corpus->title . " -- distribution of query solutions", 
	                       $Config->css_path, 
	                       array('cword', 'distTableSort'));
	
	echo print_distribution_control($dist_info);

	
	if (DistInfo::PROG_UI_FREQS == $dist_info->program)
		do_distribution_freq_extremes($dist_info);
	else
		do_distribution_classifications($dist_info);
	
	
	
// show_var($dist_info);
	
	echo print_html_footer('dist');
}


cqpweb_shutdown_environment();

exit(0);


