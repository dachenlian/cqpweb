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
 * A very short script, which turns the parameters from the wordlookup form into parameters for
 * concordance.php and then calls a "location".
 * 
 * We do not even need to set up the environment, since even with a user trying to access a corpus 
 * they shouldn't, we can pass-through to concordance.inc.php.
 * 
 * (Actually, this script could be merged into concordance.php quitre easily with the 
 * theData prep under an "if program=lookup" branch.)
 */

/* Allow for usr/xxxx/corpus: if we are 3 levels down instead of 2, move up two levels in the directory tree */
if (! is_dir('../lib'))
	chdir('../../../exe');


//require('../lib/environment.inc.php'); // needed?

require('../lib/library.inc.php');
//require('../lib/user-lib.inc.php'); // needed?
require('../lib/concordance-lib.inc.php');
require('../lib/exiterror.inc.php');

if (isset($_GET['lookupString']))
	$theData = prepare_query_string($_GET['lookupString']);
else
	exiterror('The content of the query was not specified!');

switch($_GET['lookupType'])
{
	case 'end':
		$theData = '*' . $theData;
		break;
		
	case 'begin':
		$theData = $theData . '*';
		break;
		
	case 'contain':
		$theData = '*' . $theData . '*';
		break;
		
	case 'exact':
	default:
		break;
}



$url = "concordance.php?program=lookup&lookupShowWithTags=" . ($_GET['lookupShowWithTags'] ? '1' : '0');

$url .= "&theData=". urlencode($theData); 

$url .= "&pp=" . (int)$_GET['pp'];

$url .= '&qmode=sq_nocase'; 
// TODO should this not be set according to the default case of the corpus? 
// could be done in the form. Or in conc.php if this script is merged into that one.

$url .= '&del=begin&t=&del=end&uT=y';

header("Location: $url");

/* end of script */



