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
 * This file contains the script for actions affecting corpus settings etc.
 * 
 * Currently, mnay of these things are done using execute.php.
 * 
 * However, once people can index their own corpora, this will not be an option:
 * we do not allow non-admin users to use that script!
 */

/* Allow for usr/xxxx/corpus: if we are 3 levels down instead of 2, move up two levels in the directory tree */
if (! is_dir('../lib'))
	chdir('../../../exe');

require('../lib/environment.inc.php');


/* include all function files */
include('../lib/admin-lib.inc.php');
include('../lib/cqp.inc.php');
include('../lib/exiterror.inc.php');
include('../lib/freqtable.inc.php');
include('../lib/html-lib.inc.php');
include('../lib/library.inc.php');
include('../lib/metadata.inc.php');
include('../lib/user-lib.inc.php');
include('../lib/templates.inc.php');
include('../lib/xml.inc.php');


cqpweb_startup_environment( CQPWEB_STARTUP_DONT_CONNECT_CQP , RUN_LOCATION_CORPUS );


/* check: if this is a system corpus, only let the admin use. */
if (true /* user corpus test to go here */ )
	if (!$User->is_admin())
		exiterror("Non-admin users do not have permission to perform this action.");
/* When we have user-corpora, users should be able to call this script on their own corpora (for some functions; maybe not all). 
 * That is why we do the admin check here, rather than by just asking cqpweb_startup_environment() to do it for us. */


/* set a default "next" location..." */
$next_location = "index.php?thisQ=corpusSettings&uT=y";
/* cases are allowed to change this */

$script_action = isset($_GET['caAction']) ? $_GET['caAction'] : false; 


switch ($script_action)
{
	/*
	 * =======================
	 * CORPUS SETTINGS ACTIONS
	 * =======================
	 */
	
case 'updateVisibility':
	
	if (!isset($_GET['newVisibility']))
		exiterror("Missing parameter for new Visibility setting.");

	update_corpus_visible($_GET['newVisibility'], $Corpus->name);

	break;
	
	
	
	/*
	 * ==================
	 * ANNOTATION ACTIONS
	 * ==================
	 */
	
case 'updateAnnotationInfo':
	
	/* we have incoming annotation metadata to update */
	if (! isset($_GET['annotationHandle']))
		exiterror("Cannot update annotation info:  no handle specified.");
	if (! array_key_exists($_GET['annotationHandle'], get_corpus_annotations($Corpus->name)))
		exiterror('Cannot update ' . escape_html($_GET['annotationHandle']) . ' - not a real annotation!');
	
	update_all_annotation_info( $Corpus->name, 
								$_GET['annotationHandle'], 
								isset($_GET['annotationDescription']) ? $_GET['annotationDescription'] : NULL, 
								isset($_GET['annotationTagset'])      ? $_GET['annotationTagset']      : NULL, 
								isset($_GET['annotationURL'])         ? $_GET['annotationURL']         : NULL
								);
	
	$next_location = "index.php?thisQ=manageAnnotation&uT=y";
	
	break;
	
	
case 'updateCeqlBinding':

	/* we have incoming values from the CEQL table to update */
	$changes = array();
	if (isset($_GET['setPrimaryAnnotation']))
		$changes['primary_annotation']   = ($_GET['setPrimaryAnnotation']   == '~~UNSET' ? NULL : $_GET['setPrimaryAnnotation']);
	if (isset($_GET['setSecondaryAnnotation']))
		$changes['secondary_annotation'] = ($_GET['setSecondaryAnnotation'] == '~~UNSET' ? NULL : $_GET['setSecondaryAnnotation']);
	if (isset($_GET['setTertiaryAnnotation']))
		$changes['tertiary_annotation']  = ($_GET['setTertiaryAnnotation']  == '~~UNSET' ? NULL : $_GET['setTertiaryAnnotation']);
	if (isset($_GET['setMaptable']))
		$changes['tertiary_annotation_tablehandle'] = ($_GET['setMaptable'] == '~~UNSET' ? NULL : $_GET['setMaptable']);
	if (isset($_GET['setComboAnnotation']))
		$changes['combo_annotation']     = ($_GET['setComboAnnotation']     == '~~UNSET' ? NULL : $_GET['setComboAnnotation']);
	
	if (! empty($changes))
		update_corpus_annotation_info($changes, $Corpus->name);

	$next_location = "index.php?thisQ=manageAnnotation&uT=y";
	
	break;
	
	
	
	
	/*
	 * ===========================
	 * GLOSS VISUALISATION ACTIONS
	 * ===========================
	 */

case 'updateGloss':
	
	$annotations = get_corpus_annotations($Corpus->name);
	
	if (isset($_GET['updateGlossAnnotation']))
	{
		/* we overwrite the values in the global object too so that after
		 * we update the database, the global object still matches it
		 * (this is really just for convenience as the glocal object is not 
		 * used in this script) */
		switch($_GET['updateGlossShowWhere'])
		{
		case 'both':
			$Corpus->visualise_gloss_in_context = true;
			$Corpus->visualise_gloss_in_concordance = true;
			break;
		case 'concord':
			$Corpus->visualise_gloss_in_context = false;
			$Corpus->visualise_gloss_in_concordance = true;
			break;
		case 'context':
			$Corpus->visualise_gloss_in_context = true;
			$Corpus->visualise_gloss_in_concordance = false;
			break;
		default:
			$Corpus->visualise_gloss_in_context = false;
			$Corpus->visualise_gloss_in_concordance = false;
			break;			
		}
		if ($_GET['updateGlossAnnotation'] == '~~none~~')
			$_GET['updateGlossAnnotation'] = NULL;
		if (array_key_exists($_GET['updateGlossAnnotation'], $annotations) || empty($_GET['updateGlossAnnotation']))
		{
			$Corpus->visualise_gloss_annotation = $_GET['updateGlossAnnotation'];
			update_corpus_visualisation_gloss(  $Corpus->visualise_gloss_in_concordance, 
												$Corpus->visualise_gloss_in_context, 
												$Corpus->visualise_gloss_annotation
												);
		}
		else
			exiterror("A non-existent annotation was specified to be used for glossing.");
	}
	else
		exiterror("Missing parameter; CQPweb aborts.");
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;
	
	
case 'updateTranslate':
	
	$s_attributes = list_xml_all($Corpus->name);
	
	if (isset($_GET['updateTranslateXML']))
	{	
		/* see note above re overwrite of global object */
		switch($_GET['updateTranslateShowWhere'])
		{
		case 'both':
			$Corpus->visualise_translate_in_context = true;
			$Corpus->visualise_translate_in_concordance = true;
			break;
		case 'concord':
			$Corpus->visualise_translate_in_context = false;
			$Corpus->visualise_translate_in_concordance = true;
			break;
		case 'context':
			$Corpus->visualise_translate_in_context = true;
			$Corpus->visualise_translate_in_concordance = false;
			break;
		default:
			$Corpus->visualise_translate_in_context = false;
			$Corpus->visualise_translate_in_concordance = false;
			break;			
		}
		if ($_GET['settingsUpdateTranslateXML'] == '~~none~~')
			$_GET['settingsUpdateTranslateXML'] = NULL;
		if (array_key_exists($_GET['settingsUpdateTranslateXML'], $s_attributes) || empty($_GET['settingsUpdateTranslateXML']))
		{
			$Corpus->visualise_translate_s_att = $_GET['settingsUpdateTranslateXML'];
			update_corpus_visualisation_translate($Corpus->visualise_translate_in_concordance, $Corpus->visualise_translate_in_context, 
												  $Corpus->visualise_translate_s_att);
		}
		else
			exiterror("A non-existent s-attribute was specified to be used for translation.");
	}
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;

	
case 'updatePositionLabelAttribute':
	
	$s_attributes = list_xml_all($Corpus->name);

	if (isset($_GET['newPositionLabelAttribute']))
	{
		$Corpus->visualise_position_labels = true;
		$Corpus->visualise_position_label_attribute = $_GET['newPositionLabelAttribute'];
		
		if ($Corpus->visualise_position_label_attribute == '~~none~~')
		{
			$Corpus->visualise_position_labels = false;
			$Corpus->visualise_position_label_attribute = NULL;
		}
		else if ( ! array_key_exists($Corpus->visualise_position_label_attribute, $s_attributes) )
		{
			exiterror("A non-existent s-attribute was specified for position labels.");
		}
		/* so we know at this point that $Corpus->visualise_position_label_attribute contains an OK s-att */ 
		update_corpus_visualisation_position_labels($Corpus->visualise_position_labels, $Corpus->visualise_position_label_attribute);
	}
	else
		exiterror("No new s-attribute was specified for position labels.");
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;
	
	
		
	/*
	 * =========================
	 * XML VISUALISATION ACTIONS
	 * =========================
	 */

	
case 'updateBreakOnPunc':
	
	if (!isset($_GET['break']))
		exiterror('No new value supplied for break-on-punctuation setting');
	else
		do_mysql_query("update corpus_info set visualise_break_context_on_punc = " 
							. ($_GET['break'] === '1' ? '1' : '0') 
							. " where corpus = '{$Corpus->name}'");
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;

	
case 'addXmlVizConcJS':
	
	$field = 'visualise_conc_extra_js';
	/* INTENTIONAL case fall-thru for code efficiency....... */
	
case 'addXmlVizContextJS':

	/* this "if" sets up this case statement (does not take effect if we have fallen-through from above */
	if ('addXmlVizContextJS' == $script_action)
		$field = 'visualise_context_extra_js';

	$previous = preg_split('/~/', $Corpus->$field, -1, PREG_SPLIT_NO_EMPTY);
	
	if (isset($_GET["newFile"]))
	{
		if (! preg_match('/\.js$/', $_GET["newFile"]))
			exiterror("That file does not have a valid name for a client side code file.");
		if (file_exists('../jsc/'.$_GET["newfile"]))
		{
			$previous[] = $_GET["newFile"];
			sort($previous);
			$newval = mysql_real_escape_string(implode('~', array_unique($previous)));
			do_mysql_query("update corpus_info set $field = '$newval' where corpus = '{$Corpus->name}'");
		}
		else
			exiterror("The specified file does not exist in the folder for client side code.");
	}
	else
		exiterror("No filename supplied!");
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;

	
	/*
	 * a meta-coding note:
	 * 
	 * The following two cases for CSS largely duplicate the previous two cases for JS.
	 * I couldn't think of a way to factor out the commonalities that would not cause 
	 * AWFULNESS OF AWFULLITY in terms of the readability of the code.
	 * So we live with the duplication however distasteful it might be.
	 * 
	 * The "remove", however, was much easier factor out. So the casers are merged. See below.
	 */

	
case 'addXmlVizConcCSS':
	
	$field = 'visualise_conc_extra_css';
	/* INTENTIONAL case fall-thru for code efficiency....... */
	
case 'addXmlVizContextCSS':

	/* this "if" sets up this case statement (does not take effect if we have fallen-through from above */
	if ('addXmlVizContextCSS' == $script_action)
		$field = 'visualise_context_extra_css';
	
	$previous = preg_split('/~/', $Corpus->$field, -1, PREG_SPLIT_NO_EMPTY);
	
	if (isset($_GET["newFile"]))
	{
		if (! preg_match('/\.css$/', $_GET["newFile"]))
			exiterror("That file does not have a valid name for a CSS stylesheet file.");
		if (file_exists('../css/'.$_GET["newfile"]))
		{
			$previous[] = $_GET["newFile"];
			sort($previous);
			$newval = mysql_real_escape_string(implode('~', array_unique($previous)));
			do_mysql_query("update corpus_info set $field = '$newval' where corpus = '{$Corpus->name}'");
		}
		else
			exiterror("The specified file does not exist in the folder for CSS stylesheets.");
	}
	else
		exiterror("No filename supplied!");
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;


case 'removeXmlVizConcJS':	
case 'removeXmlVizConcCSS':
	
	preg_match ('/(CSS|JS)$/', $script_action, $m);
	$field = 'visualise_conc_extra_' . strtolower($m[1]);
	/* INTENTIONAL case fall-thru for code efficiency....... */

case 'removeXmlVizContextJS':
case 'removeXmlVizContextCSS':	

	/* this "if" sets up this case statement (does not take effect if we have fallen-through from above */
	if (preg_match ('/removeXmlVizContext(CSS|JS)$/', $script_action, $m))
		$field = 'visualise_context_extra_' . strtolower($m[1]);
	
	$previous = preg_split('/~/', $Corpus->$field, -1, PREG_SPLIT_NO_EMPTY);
	
	if (isset($_GET['fileRemove']))
	{
		if (false !== ($k = array_search($_GET['fileRemove'], $previous)))
		{
			unset($previous[$k]);
			$newval = mysql_real_escape_string(implode('~', $previous));
			do_mysql_query("update corpus_info set $field = '$newval' where corpus = '{$Corpus->name}'");
		}
	}
	else
		exiterror("No filename supplied!");
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;
	

case 'importXmlViz':
	
	if (! isset($_GET['templateViz']))
		exiterror("Badly formatted import-template request");
	
	$source_template = (int)$_GET['templateViz'];
	$target_corpus = $Corpus->name;
	
	xml_visualisation_import_from_template($source_template, $target_corpus);
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;
	

case 'createXmlViz':

	if (!isset($_GET['xmlVizElement']) || ! array_key_exists($_GET['xmlVizElement'], list_xml_all($Corpus->name)))
		exiterror("Non-valid XML attribute was specified!");
	
	if (!isset($_GET['xmlVizIsStartTag'], $_GET['xmlVizHtml'], $_GET['xmlVizUseInConc'], $_GET['xmlVizUseInContext']))
		exiterror("Malformed input for creation of an XML visualisation!");
	
	$condition = (empty($_GET['xmlVizCondition']) ? '' : $_GET['xmlVizCondition']);

	xml_visualisation_create(	$Corpus->name, 
								$_GET['xmlVizElement'], 
								(bool) $_GET['xmlVizIsStartTag'], 
								$condition, 
								$_GET['xmlVizHtml'], 
								(bool) $_GET['xmlVizUseInConc'], 
								(bool) $_GET['xmlVizUseInContext'],
								(bool) $_GET['xmlVizUseInDownload']
							);
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;
	
	
case 'updateXmlViz':

	if (!isset($_GET['vizToUpdate']))
		exiterror("Incomplete update request: cannot update unspecified visualisation.");

	/* update the html */

	if (isset($_GET['xmlVizRevisedHtml']))
		xml_visualisation_update_html($_GET['vizToUpdate'], $_GET['xmlVizRevisedHtml']);
	
	/* update use in concordance / context / downloadsettings */
	
	if (isset($_GET['xmlVizUseInConc']))
		xml_visualisation_use_in_concordance($_GET['vizToUpdate'], (bool) $_GET['xmlVizUseInConc']);
	if (isset($_GET['xmlVizUseInContext']))
		xml_visualisation_use_in_context($_GET['vizToUpdate'], (bool) $_GET['xmlVizUseInContext']);
	if (isset($_GET['xmlVizUseInDownload']))
		xml_visualisation_use_in_download($_GET['vizToUpdate'], (bool) $_GET['xmlVizUseInDownload']);
	
// 	if (isset($_GET['xmlVizUseInSelector']))
// 	{
// 		$in_conc    = ( $_GET['xmlVizUseInSelector'] == 'in_conc'    || $_GET['xmlVizUseInSelector'] == 'both' );
// 		$in_context = ( $_GET['xmlVizUseInSelector'] == 'in_context' || $_GET['xmlVizUseInSelector'] == 'both' );
		
// 		xml_visualisation_use_in_concordance($_GET['vizToUpdate'], $in_conc);
// 		xml_visualisation_use_in_context($_GET['vizToUpdate'], $in_context);
// 	}
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';

	break;
	
	
case 'deleteXmlViz':
	
	if (! isset($_GET['toDelete']))
		exiterror("No visualisation to delete was provided!");
		
	xml_visualisation_delete((int) $_GET['toDelete'], true);
	
	$next_location = 'index.php?thisQ=manageVisualisation&uT=y';
	
	break;
	
	

	
	/*
	 * ==========================
	 * ADD DATA TO CORPUS ACTIONS
	 * ==========================
	 */
	

case 'extraPatt':
	
	//TODO
	
	$next_location = 'index.php?thisQ=manageAnnotation&uT=y';
	
	break;
	
	
case 'extraSatt':
	
	if (!isset($_GET['newAttHandle']))
		exiterror("No handle was specified for the new attribute.");
	$handle = cqpweb_handle_enforce($_GET['newAttHandle']);
	
	if (!isset($_GET['newAttDesc']))
		$description = '';
	else
		$description = $_GET['newAttDesc'];
	
	if (!isset($_GET['dataFile']))
		exiterror("No data file was specified for the new attribute.");
	$path = "{$Config->dir->upload}{$_GET['dataFile']}";

	if (!is_file($path))
		exiterror("A nonexistent input file for the new s-attribute was specfied.");
	
	if (!isset($_GET['addType']))
		exiterror("Missing parameter: the type of s-attribute to add was not specified.");

	if ('~newElement' == $_GET['addType'])
	{
		add_new_xml_to_corpus($Corpus->name, $handle, $handle, $description, METADATA_TYPE_NONE, $path);
	}
	else if (preg_match('/^value~(\w+)$/', $_GET['addType'], $m))
	{
		$att_family = $m[1];
		
		/* if the user has already givent he prefix, don';t add it. But if they haven't, add it. */
	
		if (!preg_match("/^{$att_family}_", $handle))
			$handle = $att_family . '_' . $handle;

		$dt = (isset($_GET['datatype']) ? (int)$_GET["datatype"] : METADATA_TYPE_FREETEXT);
		if (!metadata_valid_datatype($dt))
			exiterror("Invalid attribute datatype supplied for new s-attribute!");

		add_new_xml_to_corpus($Corpus->name, $handle, $att_family, $description, $dt, $path);
	}
	else
		exiterror("An invalid type of s-attribute was specified to be added.");
	
	$next_location = 'index.php?thisQ=manageXml&uT=y';
	
	break;
	
	
	
case 'extraMeta':

	if (!isset($_GET['newFieldHandle']))
		exiterror("No handle was specified for the new metadata field.");
	$handle = cqpweb_handle_enforce($_GET['newFieldHandle']);
	
	if (!isset($_GET['newFieldDesc']))
		$description = '';
	else
		$description = $_GET['newFieldDesc'];
	
	if (!isset($_GET['dataFile']))
		exiterror("No data file was specified for the new metadata field.");
	$path = "{$Config->dir->upload}{$_GET['dataFile']}";
	if (!is_file($path))
		exiterror("A nonexistent input file for the new metadata field was specfied.");

	$dt = (isset($_GET['datatype']) ? (int)$_GET["datatype"] : METADATA_TYPE_FREETEXT);
	if (!metadata_valid_datatype($dt))
		exiterror("Invalid attribute datatype supplied for new s-attribute!");
		
	if (!isset($_GET['target']))
		exiterror("Missing parameter: the type of s-attribute to add was not specified.");
	
	if ('--t' == $_GET['target'])
	{
		/* add field to text metadata */
		add_text_metadata_field($Corpus->name, $handle, $description, $dt, $path);
	}
	else
	{
		/* add field to idlink metadata */
		$att_handle = $_GET['target'];
		
		$xml = get_all_xml_info($Corpus->name);
		
		if (!array_key_exists($att_handle, $xml))
			exiterror("The specified IDLINK-type XML attribute does not exist.");
		if (METADATA_TYPE_IDLINK != $xml[$att_handle]->datatype)
			exiterror("The XML attribute you specified is not of IDLINK datatype.");
		
		add_idlink_field($Corpus->name, $att_handle, $handle, $description, $dt, $path);
	}

	$next_location = 'index.php?thisQ=manageMetadata&uT=y';
	
	break;
	

default:

	exiterror("No valid action specified for corpus administration.");
	break;


} /* end the main switch */



if (isset($next_location))
	set_next_absolute_location($next_location);

cqpweb_shutdown_environment();

exit(0);


/* ------------- *
 * END OF SCRIPT *
 * ------------- */

