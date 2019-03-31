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
 * Library of database-access functions for dealing with metadata tables, annotation, etc.
 * 
 * For historical reasons, everything that deals with the "corpus" as a database object is here,
 * even if it isn't really "metadata" per se. This may change ("corpus-info.inc.php").
 * 
 * There are three different "objects" dealt with here - in the loose sense, i.e.e "object"
 * not as an object-in-code, but as a "thing that exists in CQPweb".
 * 
 * ===============================================================================
 * 
 * The first is the CORPUS (and the related minor object of a CORPUS CATEGORY).
 * 
 * Note that all the corpus functions were written long, long ago, when CQPweb only ever
 * operated "inside" a particular corpus:  the admin interface, main entry screen, and user
 * account screen were not even twinkles in my eye. So, at the time, the corpus argument
 * was totally superfluous: the corpus name could always be globalled in. 
 * 
 * In retrospect, and with an extra 8 years or so of software dev experience,
 * the shortcomings of this design are pretty fucking obvious.
 * 
 * That led, circa 2015, to the abomination that is the function safe_specified_or_global_corpus()
 * which globals the corpus name in if its parameter is NULL, but otherwise accepts a string
 * parameter.
 * 
 * And yes, I know I should have used integer IDs for database objects. 
 * I was in fact teaching myself both PHP and MySQL at the time. 
 * 
 * ===============================================================================
 * 
 * The second object here is the ANNOTATION.
 * 
 * An annotation is CQPweb's wrap-around of the CWB p-attribute. It consists of the p-attribute
 * (obviously) but also other info about it, such as its tagset, documentation links, etc.
 * 
 * ===============================================================================
 * 
 * The third object here is the METADATA - a single field in either the text-metadata table,
 * or an attribute in the XML structure. 
 * 
 * All metadata has a DATATYPE, and may also have a list of categories (in assoc table) if it
 * is a classification.
 * 
 * ===============================================================================
 * 
 * The three objects may be split out into different library files in the fullness of time, as this one gets too crowded. 
 */


/*
 * =================================
 * DEALING WITH THE CORPUS AS ENTITY
 * =================================
 */


/**
 * Queries whether a given corpus name exists on the system.
 * 
 * @param unknown $corpus
 */
function corpus_exists($corpus)
{
	static $existing = NULL;
	if (is_null($existing))
		$existing = list_corpora();
	return in_array($corpus, $existing);
}


/** 
 * Returns a list of currently-defined corpus categories, as an array (integer keys = id numbers).
 * 
 * This list is never empty (if the database table is empty, a default entry "uncategorised" is created
 * with id number 1 (since 1 is the default category that new corpora have first off....).
 */
function list_corpus_categories()
{
	$result = do_mysql_query("select id, label from corpus_categories order by sort_n asc");
	if (mysql_num_rows($result) < 1)
	{
		do_mysql_query("ALTER TABLE corpus_categories AUTO_INCREMENT=1");
		do_mysql_query("insert into corpus_categories (id, label, sort_n) values (1, 'Uncategorised', 0)");
		return array(1=>'Uncategorised');
	}	
	$list_of_cats = array();
	while ( ($r=mysql_fetch_row($result)) !== false )
		$list_of_cats[$r[0]] = $r[1];
	return $list_of_cats;
}


function update_corpus_category_sort($category_id, $new_sort_n)
{
	$category_id = (int)$category_id;
	$new_sort_n = (int)$new_sort_n;
	do_mysql_query("update corpus_categories set sort_n = $new_sort_n where id = $category_id");
}

function delete_corpus_category($category_id)
{
	$category_id = (int)$category_id;
	do_mysql_query("delete from corpus_categories where id = $category_id");	
}

function add_corpus_category($label, $initial_sort_n = 0)
{
	$label = mysql_real_escape_string($label);
	if (empty($label))
		return;
	$initial_sort_n = (int)$initial_sort_n;
	do_mysql_query("insert into corpus_categories (label, sort_n) values ('$label', $initial_sort_n)");
}


/** 
 * Returns a list of all the corpora (referred to by the corpus name strings) currently in the system, as a flat array.
 */
function list_corpora()
{
	$list_of_corpora = array();
	$result = do_mysql_query("select corpus from corpus_info");
	while ( ($r=mysql_fetch_row($result)) !== false )
		$list_of_corpora[] = $r[0];
	return $list_of_corpora;
}



/**
 * A quick way to get a list of the fields in the corpus_info table - which some of the functions
 * dealing with that table need.
 */
function get_corpus_info_sql_fields()
{
	static $cache = NULL;
	
	if (is_null($cache))
		$cache = array_keys(mysql_fetch_assoc(do_mysql_query("select * from corpus_info limit 1")));

	return $cache;
}

/**
 * Gets a database info object for the specified corpus.
 * Returns false if the string argument is not a handle for an existing corpus.
 */
function get_corpus_info($corpus)
{
	$corpus = mysql_real_escape_string($corpus);
	$result = do_mysql_query("select * from corpus_info where corpus = '$corpus'");
	if (0 == mysql_num_rows($result))
		return false;
	else 
		return mysql_fetch_object($result);
}

/**
 * Gets an array of corpus_info objects. The array keys are the corpus
 * handles (the corpus field in the database). The array is sorted by these keys.
 * 
 * If a regex argument is supplied, then only corpora whose "name" 
 * (the MySQL 'corpus' field) matches the regex will be included in the returned array.
 * Regex syntax is PCRE, not MySQL-REGEXP!
 */
function get_all_corpora_info($regex = false)
{
	$list = array();
	$result = do_mysql_query("select * from corpus_info order by corpus asc");
	while (false !== ($o = mysql_fetch_object($result)))
	{
		if ($regex)
			if (! preg_match("|$regex|", $o->corpus))
				continue;
		$list[$o->corpus] = $o;
	}
	return $list;
}



/** returns a list of all the texts in the specified corpus, as an array */
function corpus_list_texts($corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	$list_of_texts = array();
	$result = do_mysql_query("select text_id from text_metadata_for_" . mysql_real_escape_string($corpus));
	while ( false !== ($r=mysql_fetch_row($result)))
		$list_of_texts[] = $r[0];
	return $list_of_texts;
}




function text_metadata_table_exists($corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);

	return (0 < mysql_num_rows(do_mysql_query("show tables like 'text_metadata_for_$corpus'")));
}



/**
 * Gets an item of corpus metadata, either from the corpus_info table, or the corpus_metadata_variable table.
 */
function get_corpus_metadata($field, $corpus = NULL)
{
	$corpus_sql_name = safe_specified_or_global_corpus($corpus);
	/* use of the longer variable name here is a method of avoiding confusion with $Corpus, which is used below). 

	/* we either interrogate corpus_info or corpus_metadata_variable */
	if (in_array($field, get_corpus_info_sql_fields()))
	{
		global $Corpus;
		/* if we are interrogating the global corpus, we do not need to re-query the database. */
		if ($Corpus->specified && $corpus_sql_name == $Corpus->name)
			return $Corpus->$field;
		else
			$result = do_mysql_query("select $field from corpus_info where corpus = '$corpus_sql_name'");
	}
	else
		$result = do_mysql_query("select value from corpus_metadata_variable where corpus = '$corpus_sql_name' AND attribute = '$field'");

	/* was data found? */
	if ($result !== false && mysql_num_rows($result) != 0)
		list($value) = mysql_fetch_row($result);
	else
		$value = "";

	return $value;
}

/**
 * Update one or more of the corpus annotation fields (primary, 2ndary etc.) - pass in values to update as a
 * map [field=>value]. If a field is not in there, it is left unchanged; any empty value sets the DB field to NULL.
 */  
function update_corpus_annotation_info($update_array, $corpus = NULL)
{
	static $updatable_fields = array (
						'primary_annotation', 
						'secondary_annotation', 
						'tertiary_annotation',
						'tertiary_annotation_tablehandle',
						'combo_annotation'
						);

	$corpus = safe_specified_or_global_corpus($corpus);
	
	foreach ($updatable_fields as $field)
		if (array_key_exists($field, $update_array))
			do_mysql_query("update corpus_info set $field = " 
				. (empty($update_array[$field]) ? 'NULL' : ("'". mysql_real_escape_string($update_array[$field]) . "'") )
				. " where corpus = '$corpus'");
}

function update_corpus_category($newcat, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newcat = (int)$newcat;
	do_mysql_query("update corpus_info set corpus_cat = $newcat where corpus = '$corpus'");
}

function update_corpus_title($newtitle, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newtitle = mysql_real_escape_string($newtitle);
	do_mysql_query("update corpus_info set title = '$newtitle' where corpus = '$corpus'");
}

function update_corpus_css_path($newpath, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newpath = mysql_real_escape_string($newpath);
	do_mysql_query("update corpus_info set css_path = '$newpath' where corpus = '$corpus'");
}

function update_corpus_external_url($newurl, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newurl = mysql_real_escape_string($newurl);
	do_mysql_query("update corpus_info set external_url = '$newurl' where corpus = '$corpus'");
}
	
function update_corpus_primary_classification_field($newclassification, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newclassification = mysql_real_escape_string($newclassification);
	do_mysql_query("update corpus_info set primary_classification_field = '$newclassification' where corpus = '$corpus'");
}

function update_corpus_main_script_is_r2l($newval, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$sqlbool = ($newval ? '1' : '0');
	do_mysql_query("update corpus_info set main_script_is_r2l = $sqlbool where corpus = '$corpus'");
}

function update_corpus_uses_case_sensitivity($newval, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$sqlbool = ($newval ? '1' : '0');
	do_mysql_query("update corpus_info set uses_case_sensitivity = $sqlbool where corpus = '$corpus'");
}

function update_corpus_conc_scope($newcount, $newunit, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newcount = (int) $newcount;
	$newunit  = mysql_real_escape_string($newunit);
	do_mysql_query("update corpus_info set conc_scope = $newcount, conc_s_attribute = '$newunit' where corpus = '$corpus'");
}

function update_corpus_initial_extended_context($newval, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newval = (int)$newval;
	do_mysql_query("update corpus_info set initial_extended_context = $newval where corpus = '$corpus'");
}

function update_corpus_max_extended_context($newval, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newval = (int)$newval;
	do_mysql_query("update corpus_info set initial_extended_context = $newval where corpus = '$corpus'");
}

function update_corpus_alt_context_word_att($newval, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$newval = mysql_real_escape_string($newval);
	do_mysql_query("update corpus_info set alt_context_word_att = '$newval' where corpus = '$corpus'");
}

function update_corpus_visible($newval, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$sqlbool = ($newval ? '1' : '0');
	do_mysql_query("update corpus_info set visible = $sqlbool where corpus = '$corpus'");

}

function update_corpus_visualisation_position_labels($show, $attribute, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$show = ($show ? '1' : '0');
	$attribute = mysql_real_escape_string($attribute);
	do_mysql_query("update corpus_info set 
							visualise_position_labels = $show,
							visualise_position_label_attribute = '$attribute' 
						where corpus = '$corpus'");
}

function update_corpus_visualisation_gloss($in_concordance, $in_context, $annot, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$in_concordance = ($in_concordance ? '1' : '0');
	$in_context = ($in_context ? '1' : '0');
	$annot = mysql_real_escape_string($annot);
	do_mysql_query("update corpus_info set 
							visualise_gloss_in_concordance = $in_concordance,
							visualise_gloss_in_context = $in_context,
							visualise_gloss_annotation = '$annot' 
						where corpus = '$corpus'");
}

function update_corpus_visualisation_translate($in_concordance, $in_context, $s_att, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$in_concordance = ($in_concordance ? '1' : '0');
	$in_context = ($in_context ? '1' : '0');
	$s_att = mysql_real_escape_string($s_att);
	do_mysql_query("update corpus_info set 
							visualise_translate_in_concordance = $in_concordance,
							visualise_translate_in_context = $in_context,
							visualise_translate_s_att = '$s_att' 
						where corpus = '$corpus'");
}


/**
 * Updates the corpus sizes in the database.
 */
function update_corpus_size($corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
// 	$result = do_mysql_query("select sum(words), count(*) from text_metadata_for_$corpus");
// 	list($ntok, $ntext) = mysql_fetch_row($result);
//	new method used instead because the above gives bad results if there are tokens-outside-texts errors.
	$result = do_mysql_query("select count(*) from text_metadata_for_$corpus");
	list($ntext) = mysql_fetch_row($result);

	$info = get_corpus_info($corpus);
	global $cqp;
	if (empty($cqp))
		connect_global_cqp();
	$cqp->set_corpus($info->cqp_name);
	$ntok = $cqp->get_corpus_tokens();
	do_mysql_query("update corpus_info set size_tokens = $ntok, size_texts = $ntext where corpus = '$corpus'");
}

/**
 * Updates the number of word types in the corpus. (Requires freq lists to be set up, returns false if they aren't.)
 */
function update_corpus_n_types($corpus = NULL)
{
	/* potentially lengthy operation... */
	$corpus = safe_specified_or_global_corpus($corpus);
	if (0 < mysql_num_rows(do_mysql_query("show tables like 'freq_corpus_{$corpus}_word'")))
	{
		list($types) = mysql_fetch_row(do_mysql_query("select count(distinct(item)) from freq_corpus_{$corpus}_word"));
		// TODO. why not just count(*) ???  in this table all items are always distinct. 
		do_mysql_query("update corpus_info set size_types = $types where corpus = '$corpus'");
		return true;
	}
	else
		return false;
}




/**
 * Returns as integer the number of tokens in this corpus.
 */
function get_corpus_wordcount($corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	list ($words) = mysql_fetch_row(do_mysql_query("select size_tokens from corpus_info where corpus = '$corpus'"));

	return (int)$words;
}

/**
 * Returns as integer the number of texts in this corpus.
 */
function get_corpus_n_texts($corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	list ($n_texts) = mysql_fetch_row(do_mysql_query("select size_texts from corpus_info where corpus = '$corpus'"));

	return (int)$n_texts;
}

/**
 * Returns as integer the number of word types in this corpus. Calculates it on the fly if not available.
 * 
 * Returns zero if the number of types cannot yet be calculated.
 */
function get_corpus_n_types($corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);

	list ($types) = mysql_fetch_row(do_mysql_query("select size_types from corpus_info where corpus = '$corpus'"));

	if (empty($types))
	{
		if (update_corpus_n_types($corpus))
			return get_corpus_n_types($corpus); 
		else 
			return 0;
	}
	else
		return (int)$types;
}


/**
 * Updates the cached record of the on-disk size of the CWB indexes of a corpus, #
 * including the "__freq" corpus if it exists.
 * 
 * Note that if the corpus is cwb-external (reliant on indexes outside CWB's own folders),
 * those indexes aren't included. 
 * 
 * @param  string $corpus  Corpus to measure.
 */
function update_corpus_index_size($corpus)
{
	global $Config;
	
	$c = get_corpus_info($corpus);
	
	if (false === $c)
		return;
	
	$size = ($c->cwb_external ? 0 : recursive_sizeof_directory("{$Config->dir->index}/{$c->corpus}")); 
	
	$fdir = "{$Config->dir->index}/{$c->corpus}__freq";
	if (is_dir($fdir))
		$size += recursive_sizeof_directory($fdir);
	
	do_mysql_query("update corpus_info set size_bytes_index = $size where id = {$c->id}");
}

/**
 * @see update_corpus_index_size - this is the same, except for corpus frequency tables.
 */
function update_corpus_freqtable_size($corpus)
{
	global $Config;
	
	$c = get_corpus_info($corpus);
	
	if (false === $c)
		return;
	
	$size = get_mysql_table_size("freq_corpus_$corpus%") + get_mysql_table_size("freq_text_index_$corpus");
	
	do_mysql_query("update corpus_info set size_bytes_freq = $size where id = {$c->id}");
}










/*
 * ==================================
 * FUNCTIONS DEALING WITH ANNOTATIONS
 * ==================================
 * 
 * (Where annotations == p-attributes, as represented in the database)
 */




/**
 * Returns an associative array: the keys are annotation handles, 
 * the values are annotation descs.
 * 
 * If the corpus has no annotation, an empty array is returned. 
 * 
 * NOTE: this is NOT a list of p-attributes. In particular, there
 * is no member with the key "word". If you want that, add 'word'=>'Word'
 * manually to the returned array.
 */
function get_corpus_annotations($corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	$result = do_mysql_query("select handle, description from annotation_metadata where corpus = '$corpus'");

	$compiled = array();
	
	while (($r = mysql_fetch_row($result)) !== false)
		$compiled[$r[0]] = $r[1];

	return $compiled;
}
// tODO should the above perhaps be "lsit" for consistency with other types of data?

/**
 * Returns an associative array: the keys are annotation handles,
 * the values are objects with four members: handle, description, tagset, external_url
 */  
function get_corpus_annotation_info($corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	$result = do_mysql_query("select * from annotation_metadata where corpus = '$corpus'");

	$compiled = array();

	while (($r = mysql_fetch_object($result)) !== false)
		$compiled[$r->handle] = $r;

	return $compiled;
}

/**
 * Update an annotation's text-description, tagset, or external url (use argument "field" to specify which).
 */
function update_annotation_info($corpus, $annotation, $field, $new)
{
	switch ($field)
	{
	case 'description':
	case 'tagset':
	case 'external_url':
		break;
	default:
		exiterror("Critical error: invalid field specified for annotaiton metadata update.");
	}
	
	if (empty($new))
		$new = 'NULL';
	else
		$new = "'" . mysql_real_escape_string($new) . "'";
	$annotation = cqpweb_handle_enforce($annotation);
	$corpus = cqpweb_handle_enforce($corpus);
	
	do_mysql_query("update annotation_metadata set $field = $new where corpus = '$corpus' and handle = '$annotation'");
}

function update_all_annotation_info($corpus, $annotation, $new_desc, $new_tagset, $new_external_url)
{
	update_annotation_info($corpus, $annotation, 'description',  $new_desc);
	update_annotation_info($corpus, $annotation, 'tagset',       $new_tagset);
	update_annotation_info($corpus, $annotation, 'external_url', $new_external_url);
}

/**
 * Boolean: is $handle the handle of an actually-existing word-level annotation?
 */
function check_is_real_corpus_annotation($handle, $corpus = NULL)
{
	if ($handle == 'word')
		return true;
	$handle = mysql_real_escape_string($handle);

	$corpus = safe_specified_or_global_corpus($corpus);
	$sql = "select handle from annotation_metadata where handle='$handle' and corpus = '$corpus'";
	if (0 < mysql_num_rows(do_mysql_query($sql)))
		return true;
	else
		return false;
}

/** 
 * Returns a list of tags used in the given annotation field, 
 * derived from the corpus's freqtable. It returns a maximum of 1000 items,
 * so should only be used on fields that ACTUALLY DO just use a tagset.
 */
function corpus_annotation_taglist($field, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	/* shouldn't be necessary...  but hey */
	$field = mysql_real_escape_string($field);
	/* this function WILL NOT RUN on word - the results would be huge & unwieldy */
	if ($field == 'word')
		return array();
	
	$result = do_mysql_query("select distinct(item) from freq_corpus_{$corpus}_{$field} limit 1000");
			
	while ( false !== ($r = mysql_fetch_row($result)))
		$tags[] = $r[0];
	
	/* better would be: sort($tags, SORT_NATURAL | SORT_FLAG_CASE); but that requires PHP >= 5.4)  */
	sort($tags);
	return $tags;
}




/**
 * Gets an associative array (corpus/att handle --> description) of a-attributes
 * belonging to the specified corpus.
 * 
 * An a-attribute always has the same handle as the target corpus that it points to.
 * 
 * So there is no separate "descrition" field: it is drawn from the corpus info
 * of the target corpus of the a-attribute.
 * 
 * If there are no a-attributes on this corpus, an empty array is returned.
 * 
 * @param string $corpus  The corpus whose a-attributes are to be returned.
 */
function list_corpus_alignments($corpus)
{
	$corpus = mysql_real_escape_string($corpus);
	
	$result = do_mysql_query("select corpus_alignments.target as `targ`, corpus_info.title as `desc` 
									from corpus_alignments left join corpus_info on corpus_alignments.target = corpus_info.corpus 
									where corpus_alignments.corpus = '$corpus'");
	$list = array();
	
	while (false !== ($o = mysql_fetch_object($result)))
		$list[$o->targ] = $o->desc;
	
	return $list;
}

/**
 * Checks the global user's permissions on a set of available alignment permissions.
 * 
 * Removes any that the User does not have at least restricted-level access to.
 * 
 * @param  array $alignments  An array of alignment handle=>title pairs as returned by list_corpus_alignments()
 * @return array              Copy of input array, with any no-permission corpora deleted. 
 *                            (This may be an empty array.)
 */
function check_alignment_permissions($alignments)
{
	global $User;
	
	if ($User->is_admin())
		/* little shortcut since the admin user can always access everything. */
		return $alignments;
	else
	{
		$seek_permissions = array(PRIVILEGE_TYPE_CORPUS_FULL,PRIVILEGE_TYPE_CORPUS_NORMAL,PRIVILEGE_TYPE_CORPUS_RESTRICTED);
		
		$allowed_alignments = array();
		
		foreach ($alignments as $aligned_corpus => $desc)
		{
			foreach($User->privileges as $p)
			{
				/* check permission type FIRST to short-circuit use of in-array on non-array scope object */ 
				if (in_array($p->type, $seek_permissions) && in_array($aligned_corpus, $p->scope_object))
				{
					$allowed_alignments[$aligned_corpus] = $desc;
					break;
				}
			}
		}

		return $allowed_alignments;
	}
}





/*
 * ====================================
 * FUNCTIONS DEALING WITH TEXT METADATA
 * ====================================
 */






/** 
 * Core function for metadata: gets an array of info about this corpus' fields. 
 * Other functions that ask things about metadata fields interface to this. 
 * 
 * So this gets you "metadata about metadata", so to speak.
 * 
 * Format: an array of objects (keys = field handles). 
 * Each object has 3 members: handle, description, datatype.
 */ 
function metadata_get_array_of_metadata($corpus = NULL)    /// NB, a consistent title would be get_all_text_metadata_info()
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	$list = array();
	
	$result = do_mysql_query("SELECT handle, description, datatype FROM text_metadata_fields WHERE corpus = '$corpus'");

	while (false !== ($r = mysql_fetch_object($result)))
		$list[$r->handle] = $r;

	return $list;	
}

/** 
 * Returns true if the argument, once cast to int, is a valid datatype.
 * Otherwise returns false.
 */
function metadata_valid_datatype($type)
{
	global $Config;
	return array_key_exists((int)$type, $Config->metadata_mysql_type_map);
}

/**
 * Returns a three-member object (->handle, ->datatype, ->description) or NULL
 * if the field supplied as argument does not exist.
 * 
 * (Single-field accessor function to the data extracted in metadata_get_array_of_metadata().)
 */
function metadata_get_field_metadata($field, $corpus = NULL)
{
	$array = metadata_get_array_of_metadata($corpus);

	return (isset($array[$field]) ? $array[$field] : NULL);
}



/**
 * Returns an array of field handles for the metadata table in this corpus.
 */
function metadata_list_fields($corpus = NULL)
{
	return array_keys(metadata_get_array_of_metadata($corpus));
}



/**
 * Returns an array of arrays listing all the classification schemes & 
 * their descs for the current (or specified) corpus. 
 * 
 * Return format: array('handle'=>$the_handle,'description'=>$the_description) 
 * 
 * If the description is NULL or an empty string in the database, a copy of the handle 
 * is put in place of the description. This default functionality can be turned off 
 * by passing a FALSE argument.
 */
function metadata_list_classifications($disallow_empty_descriptions = true, $corpus = NULL)
{
	$return_me = array();

	foreach(metadata_get_array_of_metadata($corpus) as $m)
	{
		if ($m->datatype == METADATA_TYPE_CLASSIFICATION)
		{
			if ($disallow_empty_descriptions && empty($m->description))
				$m->description = $m->handle;
			$return_me[] = array('handle' => $m->handle, 'description' => $m->description);
		}
	}
	
	return $return_me;
}


/**
 * Returns true if this field name is a classification; false if it is free text.
 * 
 * An exiterror will occur if the field does not exist!
 */
function metadata_field_is_classification($field, $corpus = NULL)
{
	$obj = metadata_get_field_metadata($field, $corpus);
	if (empty($obj))
		exiterror_general("Unknown metadata field specified!\n");
	return $obj->datatype == METADATA_TYPE_CLASSIFICATION;
}



/**
 * Expands the handle of a field to its description.
 * 
 * If there is no description, the handle is returned unaltered.
 */
function metadata_expand_field($field, $corpus = NULL)
{
	$obj = metadata_get_field_metadata($field, $corpus);
	return (empty($obj) ? $field : (empty($obj->description) ? $field : $obj->description));
}


/**
 * Expands a pair of field/value handles to their descriptions.
 * 
 * Returns an array with two members: field, value - each containing the "expansion",
 * i.e. the description entry from MySQL.
 */
function metadata_expand_attribute($field, $value, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	$efield = mysql_real_escape_string($field);
	$value  = mysql_real_escape_string($value);
	
	$sql = "SELECT description FROM text_metadata_values WHERE corpus = '$corpus' AND field_handle = '$efield' AND handle = '$value'";

	$result = do_mysql_query($sql);

	if (mysql_num_rows($result) == 0)
		$exp_val = $value;
	else
	{
		list($exp_val) = mysql_fetch_row($result);
		if (empty($exp_val))
			$exp_val = $value;
	}
	
	return array('field' => metadata_expand_field($field), 'value' => $exp_val);
}




/**
 * Returns an associative array (field=>value) for the text with the specified text id.
 * 
 * If the second argument is specified, it should be an array of field handles; only those fields will be returned.
 * 
 * If the second argument is not specified, then all fields will be returned.
 */
function metadata_of_text($text_id, $fields = NULL, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);

	$text_id = mysql_real_escape_string($text_id);
	
	if (empty($fields))
		$sql_fields = '*';
	else
	{
		$fields = array_map('mysql_real_escape_string', $fields);
		$sql_fields = '`' . implode('`,`', $fields) . '`';
	}

	$sql = "select $sql_fields from text_metadata_for_$corpus where text_id = '$text_id'";
	
	return mysql_fetch_assoc(do_mysql_query($sql));
}



/**
 *  Returns a list of category handles occuring for the given classification. 
 */
function metadata_category_listall($classification, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);

	$classification = mysql_real_escape_string($classification);

	$result = do_mysql_query("SELECT handle FROM text_metadata_values WHERE field_handle = '$classification' AND corpus = '$corpus'");

	$return_me = array();
	
	while (($r = mysql_fetch_row($result)) != false)
		$return_me[] = $r[0];
	
	return $return_me;
}



/**
 * Returns an associative array of category descriptions,
 * where the keys are the handles, for the given classification.
 * 
 * If no description exists, the handle is set as the description.
 */
function metadata_category_listdescs($classification, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	$classification = mysql_real_escape_string($classification);

	$result = do_mysql_query("SELECT handle, description FROM text_metadata_values WHERE field_handle = '$classification' AND corpus = '$corpus'");

	$return_me = array();
	
	while (($r = mysql_fetch_row($result)) != false)
		$return_me[$r[0]] = (empty($r[1]) ? $r[0] : $r[1]);
	
	return $return_me;
}




/**
 * Returns a list of text IDs, plus their category for the given classification. 
 */
function metadata_category_textlist($classification, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	$classification = mysql_real_escape_string($classification);
			
	$result = do_mysql_query("SELECT text_id, $classification FROM text_metadata_for_$corpus");

	$return_me = array();
	
	while (($r = mysql_fetch_assoc($result)) != false)
		$return_me[] = $r;
	
	return $return_me;
}


/**
 * returns the size of a category within a given classification 
 * as an array with [0]=> size in words, [1]=> size in files
 */ 
function metadata_size_of_cat($classification, $category, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	$classification = mysql_real_escape_string($classification);
	$category       = mysql_real_escape_string($category);

	$sql = "SELECT sum(words) FROM text_metadata_for_$corpus where $classification = '$category'";
	list($size_in_words) = mysql_fetch_row(do_mysql_query($sql));

	$sql = "SELECT count(*) FROM text_metadata_for_$corpus where $classification = '$category'";
	list($size_in_files) = mysql_fetch_row(do_mysql_query($sql));

	return array($size_in_words, $size_in_files);
}


/** As metadata_size_of_cat(), but thins by an additional classification-catgory pair (for crosstabs) */
function metadata_size_of_cat_thinned($classification, $category, $class2, $cat2, $corpus = NULL)
{
	$corpus = safe_specified_or_global_corpus($corpus);
	
	$classification = mysql_real_escape_string($classification);
	$category       = mysql_real_escape_string($category);
	$class2         = mysql_real_escape_string($class2);
	$cat2           = mysql_real_escape_string($cat2);

	$sql = "SELECT sum(words) FROM text_metadata_for_$corpus where $classification = '$category' and $class2 = '$cat2'";
	list($size_in_words) = mysql_fetch_row(do_mysql_query($sql));

	$sql = "SELECT count(*) FROM text_metadata_for_$corpus where $classification = '$category' and $class2 = '$cat2'";
	list($size_in_files) = mysql_fetch_row(do_mysql_query($sql));

	return array($size_in_words, $size_in_files);
}




/** 
 * Counts the number of words in each text class for this corpus,
 * and updates the table containing that info.
 * 
 * This is done for a single classification, if a handle is provided;
 * if not, it is donefor all classifications.
 * 
 * @param string $corpus  The corpus.
 * @param string $handle  Optional handle of the classification to work on; 
 *                        if none is provided, all classifications are processed.
 */
function metadata_calculate_category_sizes($corpus, $handle = NULL)
{
	$corpus = mysql_real_escape_string($corpus);

	/* get a list of classification schemes */
	$sql = "select handle from text_metadata_fields where corpus = '$corpus' and datatype = " . METADATA_TYPE_CLASSIFICATION;
	if (!empty($handle))
	{
		$handle = mysql_real_escape_string($handle);
		$sql .= " and handle = '$handle'";
	}
	$result_list_of_classifications = do_mysql_query($sql);
	
	/* for each classification scheme ... */
	while( ($c = mysql_fetch_row($result_list_of_classifications) ) != false)
	{
		$classification_handle = $c[0];
		
		/* get a list of categories */
		$sql = "select handle from text_metadata_values 
						where corpus = '$corpus' and field_handle = '$classification_handle'";

		$result_list_of_categories = do_mysql_query($sql);

	
		/* for each category handle found... */
		while ( ($d = mysql_fetch_row($result_list_of_categories)) != false)
		{
			$category_handle = $d[0];
			
			/* how many files / words fall into that category? */
			$sql = "select count(*), sum(words) from text_metadata_for_$corpus 
							where $classification_handle = '$category_handle'";
			
			$result_counts = do_mysql_query($sql);

			if (mysql_num_rows($result_counts) > 0)
			{
				list($file_count, $word_count) = mysql_fetch_row($result_counts);

				$sql = "update text_metadata_values set category_num_files = '$file_count',
					category_num_words = '$word_count'
					where corpus = '$corpus' 
					and field_handle = '$classification_handle' 
					and handle = '$category_handle'";
				do_mysql_query($sql);
			}
			unset($result_counts);
		} /* loop for each category */
		
		unset($result_list_of_categories);
	} /* loop for each classification scheme */
}


/**
 * Freetext metadata fields are allowed to contain certain special forms which indicate
 * external resources of one kind or another. These are detected by examining the value's
 * "prefix", which is the part of the value before the first colon.
 * 
 * This function takes a value from a metadata table and returns a link or a video/audio/img
 * embed that can be sent to the browser.
 * 
 * @param  string $value  A text/idlink metadata table single value.
 * @return string         HTML render of the value to display.
 */
function render_metadata_freetext_value($value)
{
	/* if the value is a URL, convert it to a link;
	 * also allow audio, image, video, YouTube embeds */
	if (false !== strpos($value, ':') )
	{
		list($prefix, $url) = explode(':', $value, 2);
		
		switch($prefix)
		{
		case 'http':
		case 'https':
		case 'ftp':
			/* pipe is used as a delimiter between URL and linktext to show. */
			if (false !== strpos($value, '|'))
				list($url, $linktext) = explode('|', $value);
			else
				$url = $linktext = $value;
			$show = '<a target="_blank" href="'.$url.'">'.escape_html($linktext).'</a>';
			break;
			
		case 'youtube':
			/* if it's a YouTube URL of one of two kinds, extract the ID; otherwise, it should be a code already */
			if (false !== strpos($url, 'youtube.com'))
			{
				/* accept EITHER a standard yt URL, OR a yt embed URL. */
				if (preg_match('|(?:https?://)(?:www\.)youtube\.com/watch\?.*?v=(.*)[\?&/]?|i', $url, $m))
					$ytid = $m[1]; 
				else if (preg_match('|(?:https?://)(?:www\.)youtube\.com/embed/(.*)[\?/]?|i', $url, $m))
					$ytid = $m[1];
				else
					/* should never be reached unless bad URL used */
					$ytid = $url;
			}
			else
				$ytid = $url;
			$show = '<iframe width="640" height="480" src="http://www.youtube.com/embed/' . $ytid . '" frameborder="0" allowfullscreen></iframe>';
			break;
			
		case 'video':
			/* we do not specify height and width: we let the video itself determine that. */
			$show = '<video src="' . $url . '" controls preload="metadata"><a target="_blank" href="' . $url . '">[Click here for videofile]</a></video>';
			break;
			
		case 'audio':
			$show = '<audio src="' . $url . '" controls><a target="_blank" href="' . $url . '">[Click here for audiofile]</a></audio>';
			break;
		
		case 'image':
			/* Dynamic popup layer: see textmeta.js */
			$show = '<a class="menuItem" href="" onClick="textmeta_add_iframe(&quot;' . $url . '&quot;); return false;">[Click here to display]</a>';
			break;
					
		default;
			/* unrecognised prefix: treat as just normal value-content */
			$show = escape_html($value);
			break;
		}
	}
	/* otherwise simply escape it */
	else
		$show = escape_html($value);
	
	return $show;
}



/**
 * Add a new field to a corpus's text metadata.
 * 
 * @param string $corpus       Corpus we are adding to.
 * @param string $handle       Handle of the column to add.
 * @param string $description  Description of the column to add.
 * @param int    $datatype     Datatype constant for the new column.
 * @param string $input_path   Path to the input file: se notes on add_field_to_metadata_table().
 */
function add_text_metadata_field($corpus, $handle, $description, $datatype, $input_path)
{
	$datatype = (metadata_valid_datatype($datatype) ? (int) $datatype : METADATA_TYPE_FREETEXT);
	
	if (in_array($handle, metadata_list_fields($corpus)))
		exiterror("Cannot add metadata field $handle because a field by that name already exists.");
	
	add_field_to_metadata_table("text_metadata_for_$corpus", $handle, $datatype, $input_path);
	
	/* update the text_metadata_fields list */
	$corpus = cqpweb_handle_enforce($corpus);
	$handle = cqpweb_handle_enforce($handle);
	$description = mysql_real_escape_string($description);

	do_mysql_query("insert into text_metadata_fields 
		(corpus, handle, description, datatype) 
			VALUES 
		('$corpus','$handle','$description', $datatype)");

	/* and, if it is a classification, scan for values, then update the cat sizes for the values etc. */
	if (METADATA_TYPE_CLASSIFICATION == $datatype)
	{
		$result = do_mysql_query("select distinct($handle) from text_metadata_for_$corpus");

		while (false !== ($r = mysql_fetch_row($result)))
			do_mysql_query("insert into text_metadata_values 
				(corpus, field_handle, handle)
					values
				('$corpus', '$handle', '{$r[0]}')"
				);

		metadata_calculate_category_sizes($corpus, $handle);
	}
}

/**
 * This function adds a column to a metadata table, but *does not* 
 * do any of the associated actions e.g. inserting a line into the monitoring table,
 * or compiling-and-inserting the list of classification categories.
 * 
 * @see add_text_metadata_field
 * @see add_idlink_field
 * 
 * @param string $table        Database table we are modifying.
 * @param string $handle       Handle of the column to add.
 * @param int    $datatype     Datatype constant for the new column.
 * @param string $input_path   Fielsystem path for the input file, which should be
 *                             a plain text file wiht 2 tab-separated columns:
 *                             col 1. the ID, col 2. the new field's value for that ID.
 *                             No check is made for ID uniqueness or for presence of all IDs.
 */
function add_field_to_metadata_table($table, $handle, $datatype, $input_path)
{
	global $Config;
	
	$table = mysql_real_escape_string($table);
	$handle = cqpweb_handle_enforce($handle);
	$datatype = (int) $datatype;
	
	/* check file exists by trying to open */
	if (false === ($source = fopen($input_path, 'r')))
		exiterror("Could not open the specified metadata input file.");
	
	if (preg_match('/^text_metadata_for_/', $table))
		$id_field = 'text_id';
	else
		$id_field = '__ID';
		
	/* add column to the table; if a duplicate colmn name has been used, this will abort. Caller should also check this, for neatness. */
	do_mysql_query("alter table `$table` add column `$handle` {$Config->metadata_mysql_type_map[$datatype]}");
	/* note, ideally we'd add it "BEFROE `words`" for neatness, but this unfortunately does nto work. MySQl has only AFTER. */ 
	
	switch($datatype)
	{
	case METADATA_TYPE_CLASSIFICATION:
	case METADATA_TYPE_UNIQUE_ID:
	case METADATA_TYPE_IDLINK:
		$line_regex = '/^(\w+)\t(\w+)$/';
		break;
	default:
		/* all other datatypes */
		$line_regex = '/^(\w+)\t(.*)$/';
		break;
		/* note: this will need adjusting for dataypes such as DATE. */
	}
	
	$line_n = 0;
	
	while (false != ($line = fgets($source)))
	{
		$line_n++;
		$line = rtrim($line, "\r\n");
		if (empty($line))
			continue;

		/* check format of line */
		if (! preg_match($line_regex, $line, $m))
		{
// show_var($line_regex); show_var($line);
			do_mysql_query("alter table `$table` drop column `$handle`");
			exiterror("Badly formed line: # $line_n in file $input_path.");
		}
		$id = $m[1];
		$val = mysql_real_escape_string($m[2]);			
		
		/* insert the actual data. Doing it one at a time is abit inefficient but this is 
		 * not a time-critical action. */
		do_mysql_query("update `$table` set `$handle` = '$val' where `$id_field` = '$id'");
	}
	
	fclose($source);
	
	if (METADATA_TYPE_CLASSIFICATION == $datatype)
		do_mysql_query("alter table `$table` add index (`$handle`)");
}








/*
 * LIBRARY FOR METADATA_TYPE_DATE
 * ==============================
 */


/**
 * This class repesents a date in CQPweb metadata.
 * 
 * We need this class because MySQL dates only start at CE 1000.
 * 
 * These dates are only rough-gradience, to allow us to skim over issues like different month/year lengths,
 * Julian vs Gregorian issues, leap years, etc. etc. So we do not actually test for date validity,
 * EXCEPT that the year CANNOT be zero. So +1999_0231 will be accepted as valid.
 * 
 * Serialised format for database (and CWB!) storage:
 * 
 *  +yyyy_mmdd
 *  -yyyy_mmdd
 * 
 * Where "yyyy" is padded with zeroes; there can be any number of digits in the "yyyy",
 * but the number of digits in the mm and dd is fixed at 2; if there are 2 yy digits, 
 * this is NOT interepted as 20th/21st century but as 1st century CE.
 * 
 * Note that the leading plus or minus is NOT optional.
 */ 
class CQPwebMetaDate
{
	/* integer: years = CE; negative = BCE. */
	public $year;
	/* integer 1 to 12 */
	public $month;
	/* intetger 1 to 31 */
	public $day;

	/** 
	 * regex - including delimiters - which validates a serialised date, and also captures its 4 components
	 * (sign, year, month, day).
	 */
	const VALIDITY_REGEX = '/^([+\-])(\d+)_(\d\d)(\d\d)$/';
	
	/** Creates a date object from a serialised string. */
	public function __construct($serialised)
	{
		if (! preg_match(self::VALIDITY_REGEX, trim($serialised), $m))
			exiterror("Invalid string argument to CQPwebMetaDate() ! ");
		
		if (0  == ($this->year  = (int)$m[2]))
			exiterror("Invalid year value to CQPwebMetaDate() ! There was no year zero.");
		if ('-' == $m[1])
			$this->year *= -1;

		if (13 <= ($this->month = (int)$m[3]))
			exiterror("Invalid month value to CQPwebMetaDate() ! Month cannot be more than 12.");
		if (0 == $this->month)
			exiterror("Invalid month value to CQPwebMetaDate() ! Month cannot be zero.");
		
		if (32 <= ($this->day   = (int)$m[4]))
			exiterror("Invalid day value to CQPwebMetaDate() ! Day cannot be more than 31.");
		if (0 == $this->day)
			exiterror("Invalid day value to CQPwebMetaDate() ! Day cannot be zero.");
	}
	
	/** Gets a string serialisation of the contents of this date. */
	public function serialise()
	{
		return ($this->year < 0 ? '-' : '+') . sprintf("%04d-%02d%02d", abs($this->year), $this->month, $this->day);
	}


	/** 
	 * Returns a string that can be used to declare a MySQL field.
	 * 
	 * By default the field will be big enough to store dates from 1st Jan 9,999BCE to 31st Dec 9,999CE,
	 * i.e. a 4 digit year.
	 * 
	 * You can request a longer year by setting the argument to 5 or above.
	 */
	public function mysql_type($year_num_digits = 4)
	{
		$year_num_digits = ($year_num_digits >= 4 ? (int)$year_num_digits : 4);
		$width = $year_num_digits + 6;
		return " varchar($width) default NULL ";
	}

// TODO difference - in years and/or in months, because days is dicey? Makle this a fucntion rathwr thana method?
// have to make the difference between -1 and 1 be 1 instead of 2!
// TODO group into month buckets?
// all this stuff: wait till we find out what we need. Only put basic operations as class methods. Anything fancy should be a separate function.

}


