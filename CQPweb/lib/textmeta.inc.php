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




/* Allow for usr/xxxx/corpus: if we are 3 levels down instead of 2, move up two levels in the directory tree */
if (! is_dir('../lib'))
	chdir('../../../exe');



/* initialise variables from settings files  */
require('../lib/environment.inc.php');


/* include function library files */
require('../lib/library.inc.php');
require('../lib/user-lib.inc.php');
require('../lib/html-lib.inc.php');
require('../lib/exiterror.inc.php');
require('../lib/metadata.inc.php');


cqpweb_startup_environment(CQPWEB_STARTUP_DONT_CONNECT_CQP);




/* initialise variables from $_GET */

if (empty($_GET["text"]) )
	exiterror("No text was specified for metadata-view! Please reload CQPweb.");
else 
	$text_id = cqpweb_handle_enforce($_GET["text"]);


$field_info = metadata_get_array_of_metadata($Corpus->name);

$result = do_mysql_query("SELECT * from text_metadata_for_{$Corpus->name} where text_id = '$text_id'");

if (1 > mysql_num_rows($result))
	exiterror("The database doesn't appear to contain any metadata for text $text_id.");

$metadata = mysql_fetch_assoc($result);


/*
 * Render!
 */

echo print_html_header($Corpus->title . ': viewing text metadata -- CQPweb', $Config->css_path, array('modal', 'textmeta'));

?>

<table class="concordtable" width="100%">
	<tr>
		<th colspan="2" class="concordtable">Metadata for text <em><?php echo $text_id; ?></em></th>
	</tr>
	<tr>
		<th width="40%"></th>
		<th></th>
	</tr>

<?php

$n_words = count($metadata);

foreach ($metadata as $field => $value)
{
	if (isset($field_info[$field]))
	{
		/* standard field */
		$desc = escape_html($field_info[$field]->description);

		/* don't allow empty cells */
		if (empty($value))
			$show = '&nbsp;';		
		else if (METADATA_TYPE_FREETEXT == $field_info[$field]->datatype)
			$show = render_metadata_freetext_value($value);
		else if (METADATA_TYPE_CLASSIFICATION == $field_info[$field]->datatype)
		{
			$pair = metadata_expand_attribute($field, $value, $Corpus->name);
			$show = $pair['value'];
		}
		else
			$show = $value;
	}
	else
	{
		/* non standard, hardwired fields */
		if ($field == 'text_id')
		{
			$desc = 'Text identification code';
			$show = $value;
		}
		/* this expansion is hardwired */
		else if ($field == 'words')
		{
			$desc = 'No. words in text';
			$show = number_format($value, 0);
		}
		/* don't show the CQP delimiters for the file */
		else if ($field == 'cqp_begin' || $field == 'cqp_end')
			continue;
	}
	
	echo '<tr><td class="concordgrey">' , $desc, '</td><td class="concordgeneral">' , $show, "</td></tr>\n";
}



echo '</table>';

echo print_html_footer('textmeta');

cqpweb_shutdown_environment();


