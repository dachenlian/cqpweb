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

/*
 * @file
 * 
 * QUERY SCOPE, RESTRICTION, AND SUBCORPUS: AN EXPLANATION OF HOW IT WORKS
 * =======================================================================
 * 
 * About the Query Scope
 * ---------------------
 * 
 * Let's start by defining terms. Whenever a CQPweb query is run, it has a particular QUERY SCOPE.
 * 
 * Tracking the Query Scope of a given query is essential for many reasons. First, many followup operations
 * (e.g. the Distribution and Collocation functions inter alia) require a knowledge of the Query Scope.
 * Second, all queries are cached, and when we look up a query in the cache, it is only valid to retrieve
 * a cached result iff (a) the query term matches, (b) the Query Scope is the same (and (c) the postprocess
 * chain on each is identical, but as they say, that is another story!) The Query Scope is likewise recorded
 * in the query history and in other places.
 * 
 * A Query Scope has three possible states. Its most common is the null-state: the query runs in the whole of
 * the corpus in this case, and all subsequent analysis can run on the basis of whole-corpus frequency data. 
 * 
 * However, it can have two other states as well, either of which limits the search to a subsection of the
 * corpus. The first is the SUBCORPUS state and the second is the RESTRICTION state. Both the Subcorpus
 * and the Restriction are in addition concepts that require definitions of their own.
 * 
 * All Query Scopes can be serialised as strings (deterministically, so by comparing two serialised strings
 * it can be ascertained whether they represent the same scope or not). The rules are as follows:
 * 
 *  - WHOLE CORPUS NULL-STATE: serialises to empty string.
 *
 *  - SUBCORPUS STATE: serialises to a string that matches the regex ^\d+$ , where the digits indicate the
 *    integer value of the subcorpus's ID. This is distinguishable from the Restriction state in that the 
 *    Restriction state will always begin with a non-digit character due to the serialisation rules.  
 *    There is a special extra code for a "deleted subcorpus" -- used in the Query History table when
 *    the subcorpus in which a logged query was done has been deleted since the time the query ran.
 *  
 *  - RESTRICTION STATE: serialises to the serialisation of the Restriction.
 * 
 * [[Note that prior to v3.2.6, the Query Scope was a looser pair of properties, which stored subcorpus 
 * references and restrictions in two separate database fields. This was inefficient (because no query had
 * both), and made passing info around difficult (because characterising the nature of the scope required
 * both a $subcorpus and a $restriction variable). Making the Query Scope a separately-conceptualised entity,
 * and thus a separate code object, allows cleaner encapsulation of all this mucking about.]]
 * 
 * About the Restriction
 * ---------------------
 * 
 * A Restriction is a set of criteria defined ad hoc at the time the query is run that narrows down the section
 * of the corpus searched. These criteria may be of a number of types. 
 * 
 *  --- First, they can be *criteria on text-level metadata*. In this case, the section of the corpus 
 *      searched == all and only those texts that meet those criteria. 
 *      
 *  --- Second, they can be *criteria on some particular xml-element*. That is, referencing a single
 *      s-attribute "family" (in the CQPweb sense). In this case, the section of the corpus searched 
 *      == a complete number of ranges on a given s-attribute. The criterion here can simply be
 *      "occurs within a <xxx>" with no condition, as well as the more usual "occurs within an <xxx> where
 *      attribute aaa on element <xxx> has a value that meets the condition".
 *            
 *  --- Third, they can be *mixed criteria* - that is, criteria which apply to more than one family of 
 *      s-attribute. This can mean EITHER some conditions on <text> and then some more on an xml-element;
 *      OR some conditions on an xml-element <xxx> and then some conditions on another xml-element <yyy>.
 *      (Or, of course, the mixture might be a selection of 3+, rather than just 2, of the above.....)
 *      Either way the key thing here is that there is no guarantee that the contiguous ranges of corpus
 *      positions correspond to complete instances of any particular s-attribute. For instance, it is quite
 *      possible for one criterion to select cpos 1-100 and another to select cpos 50-150, and in this case
 *      the Restriction would contain only 50-100. 
 * 
 * The thing to note, in either case, is that the Restriction always contains within itself sufficient info,
 * in the form of the encoded criteria as described above, to derive the implicit list of cpos ranges that make
 * up the Restriction. Thus, a Restriction can be used as a Query Scope. But the information within the 
 * Restriction can always be rendered as a prose description of the criteria - so a query, or an analysis of
 * the query, can always be given a header that explains the Restriction (i.e. reminds the user of what
 * exactly it was they did on the Restricted Query form).
 * 
 * A Restriction can always be serialised as a string. This string can be used to store the Restriction itself;
 * or, it can be used as a value for a Query Scope of the Restriction kind. 
 * 
 * A history note: prior to v3.2.6, the only possible Restriction type was the first: all Restrictions were
 * thus representable as Boolean conditions in SQL code, applied to classification-type text-level metadata,
 * that could be embedded directly into the where-clause of an SQL query run across the text_metadata_for_*
 * table. Once the query-scope mechanism was expanded to allow corpus-sections that are not sets of complete
 * texts, this became insufficient.
 * 
 * About the Subcorpus
 * -------------------
 * 
 * As well as a Restriction and the whole-corpus null-state, the third possible state of a Query Scope is that it
 * may reference a Subcorpus.
 * 
 * A Subcorpus is a pre-defined slice of the corpus. They have to be set up by the user in advance (not ad hoc
 * at time of query) and they are saved as separate database entities. There are a number of mechanisms for
 * creating subcorpora.
 * 
 * First, a Subcorpus can be created via Restrictions - that is, the same interface as for a Restricted Query
 * is presented to the user, and the part of the corpus that those conditions select are stored as the content 
 * of the subcorpus. This form of subcorpus is basically equivalent to a way for the user to save their 
 * commonly-used sets of restrictions. This kind of corpus may be based on any of the range of types of
 * possible restriction outliend above: based on a whole number of complete texts, based on a whole number of
 * complete instances of a single s-attribute, or based on mixed conditions. 
 * 
 * Second, a Subcorpus can be created by the "Scan Text Metadata" tool. This allows text metadata fields that aren't
 * used in Restrictions to be searched; matching texts are included in the subcorpus. A Restriction can only
 * reference metadata fields with particular datatypes (currently only the Classification datatype, but in the future
 * the Date and Idlink datatypes will also be usable within a Restriction). The Free Text and Unique ID datatypes 
 * cannot be used in restrictions because they are expected to be non-repeating: but they *can* be used in Scan Text
 * Metadata. This process can be used to generate a list of texts that constitute a subcorpus.
 * 
 * Third, a Subcorpus can be created by the manual entry of text ID codes. (The one-Subcorpus-per-text procedure,
 * although distinct in the interface, boils down conceptually to the same thing as this.)
 * 
 * Fourth, a Subcorpus can be created from a saved query, in which case it contains all-and-only the texts with
 * at least 1 hits for that query.
 * 
 * Fifth, a Subcorpus can be created by *inversion*. In older versions of CQPweb, this meant inverting *texts* since
 * subcorpora had to be based on texts. This procedure is still possible: inverting a Subcorpus which consists of
 * texts will produce a new Subcorpus made up of all the texts in the corpus that were not in the original 
 * Subcorpuis. Inverting any other Subcorpus works the same way but at the level of (ranges of) corpus positions.
 * 
 * Sixth, a Subcorpus can be created by uploading a file in CQP-undumpable format: this is basically the same as
 * uploading a query.
 * 
 * Seventh, a Subcorpus can be created from a query in the direct fashion (i.e. the matches of the query become
 * the ranges of the Subcorpus - the way it works in commandline CQP if you activate a query).  
 * 
 * Eight, a Subcorpus can be created by editing an existing subcorpus. This applies only to subcorpora that consist
 * of a set of things that are "the same": a set of texts, primarily. The Subcorpus list of texts can be edited
 * either to add texts or to remove texts. This can be done with any subcorpus that consists only of 
 * complete texts. Similarly, for a Subcorpus based on Restrictions, if the result is a
 * set of complete instances of one particular s-attribute (rather than mixed-conditions) then the list of instances
 * can be viewed and instances removed (addition is only possible if there is xml-metadata of datatype Idlink or
 * Unique ID, since otherwise there is not anything to add!) For a Subcorpus based on mixed-conditions, or on 
 * uploaded cpos pairs, then it can be edited by removing any of the included cpos pairs, or adding new cpos pairs. 
 * 
 * [[[NOTE: the para above contains numerous to-be-implemented things, i.e. manual editing of SCs other than
 * the most common "complete set of texts" type. There is an important question here: let's say a subcorpus consists
 * some set of complete utterances (<u> for sake of argument). How should this be represented in the DB? It *could*
 * just be as a set of restrictions, but if this is then edited, we have an arbitrary set of s-attribute ranges. 
 * If <u> happens ot have a uniqid-type attribute (<u id="....") then the value of that attribute can be used.
 * As with text, it can be an id-list. Similarly if it is an idlink, then a list of linked Ids can be given and 
 * linked-ids added / removed (in this case it would be speakers for uttterances that can be added/removed). 
 * But if we imagine <u>s have neither an Idlink-type nor a Uniqid-type dependent attribute, then how do we store 
 * a list of the instances (Ranges) that the Subcorpus includes? Probably the most efficient way is simply to
 * store it as an undumpable file, which we know we need to do anyway, but with a note in the DB that the 
 * list of ranges all represent insatances of a particular s-att (so that this info can be represented in the
 * add/remove interface). FUCK ME THIS IS COMPLICATED.]]]
 * 
 * What all of the above implies is that there must exist THREE levels of representation for a Subcorpus (plus an
 * intermediate case).
 * 
 * ALL subcorpora are representable at the lowest level: a List Of Cpos Pairs. SOME subcorpora are represented only
 * at this level - for instance, those created by uploads, or directly-from-query. 
 *   --  IMPLEMENTATION: the cpos pairs are stored as a file on disk, which serves also as the undump source.
 * 
 * At the next level up, a Subcorpus is represented as a List Of Elements. This may be a traditional list of 
 * text IDs, or a list of instances of an s-attribute by the ID codes contained in an attribute of datatype
 * IDLINK or datatype UNIQUE_ID.
 *   --  IMPLEMENTATION: A database field (string) containing the list of IDs. A separate field (or header in the
 *       same field?) spelling out what s-attribute they relate to. An undump file containing the list of cpos-pairs
 *       that this resolves to is also stored (i.e. this level ALSO has the storage of the lower level).
 * Here there is also an INTERMEDIATE case: where the Subcorpus consists of a number of complete s-attribute
 * ranges, but this is not defined in terms of a UNIQUE_ID or IDLINK attribute. In this case, there can be no
 * actual list - just a theoretical list.
 *   --  IMPLEMENTATION: A database field spelling out the s-attribute, as noted, but the item-ID-list is empty
 *       (since in this case there are no IDs). We have only the list of cpos-pairs in the undump-format file.
 *       When it comes to adding/removing things from a subcorpus, it is that undump file that is referenced /
 *       modified.
 * 
 * At the highest level of abstraction, a Subcorpus is represented as Restriction. This can then resolve to 
 * an item list (for subcorpus editing) and, additionally, to a list of cpos pairs (for undumping). The list of
 * cpos pairs is cached (like for the other kinds of subcorpora!) but the list of items is not: it must be 
 * recalculated from the database and/or CWB index when necessary.
 *   --  IMPLEMENTATION: A database field which contains the Restriction conditions using the same serialisation
 *       that is used to store a Restriction elsewhere in the system. A separate field (or header
 *       in the same field?) spelling out either text or an s-attribute iff the Resctriction refers only to one 
 *       thing. 
 * 
 * Subcorpora cannot be serialised to a string in full. Whhen they are serialised, what results is a string
 * containing the decimal representation of the Subcorpus's integer ID. Therefore, when a Query Scope refers
 *  to a Subcorpus, it references it by its ID number. See above.
 *
 * What the above implies about the fields needed to represent a Subcorpus in the Database
 * ---------------------------------------------------------------------------------------
 * 
 * Identifiying fields: integer ID, plus the user+corpus it belongs to, plus the save-name given to it by the user.
 * 
 * Content fields: the restriction used to make it (if there was one). The list of items (if there is one). 
 * Note: these could just be one field (of type text), distinguished by a magic-number initial character
 * (since if the Restriction is stored, the item list never is, and vice versa). A field identifying what kind 
 * of items are stored in the item list (if there is one), which again, could be stored in that single field;
 * since the field will have to be parsed on load anyway in order to know how to do things with the Subcorpus,
 * everything might as well be rolled into it. Similarly, flags stating what kind of SC this is could be set
 * on parse of the single content field: they do not need to be stored seaprately in the DB.
 * 
 * Link fields: filename for the undump file that contains the cpos data of the Subcorpus. This could be avoided
 * iff the filename is always deducible from other info - e.g. if it is always sprintf("sc-%x", $this->id). 
 * 
 * Info fields: 2 integer fields, size in units and size in tokens. The latter is easy. The former counts in the 
 * same unit as the "kind of items" identifier (i.e. if the SC is defined as a list of sentences, this field 
 * contains the N of sentences); if there is no "kind of items", then this is the number of cpos-defined ranges
 * (i.e. if this query is based on a mixed-target-condition Restriction, or was created by upload).
 * 
 * In short we need:
 * id
 * name
 * corpus
 * user
 * content
 * n_items
 * n_tokens
 * 
 * About Last Restrictions
 * -----------------------
 * 
 * The system keeps track of the last restrictions enetered by a particular user for a particular corpus.
 * The way this is done is by saving the Restriction as a subcorpus. This subcorpus then has two roles:
 * in cases where the Last Restrictions are re-used, the serialised Restriction string is retrieved from it;
 * and the Subcorpus itself can be copied in order to store that Subcorpus normally (i.e. with a normal
 * name etc. etc.).  
 * 
 * The Last Restrictions are stored as a normal subcorpus but with the save-name '--last_restrictions'. The
 * use of a \W character means that it cannot clash with anything a user might specify, since user
 * save-names are restricted to ^\w+$. 
 * 
 * The Last Restrictions does not have a cpos file (as it is never directly used for a query - when
 * it is reloaded, it is the copied-out restirction, not the actual subcorpus, that gets used).
 * 
 * Since it would never need a cpos file for primary data (it always comes from a Restricted Query), 
 * this means everything should Just Work when the normal Subcorpus copy-mechanism is used. 
 * 
 * About Databases, Frequency Tables, and Other Stored Data
 * --------------------------------------------------------
 * 
 * The saved_dbs record refers to a Query Scope: this is serialised, as noted above (AND: just as with
 * saved queries/query history, replaces the old-style pair of "restricitons"/"subcorpus" fields).
 * 
 * The saved_freqtables record refers to a Query Scope: exactly the same applies. So, to find out if
 * a Subcorpus has a frequency table, the test is "select * from saved_freqtables where query_scope = '$id'"
 * where $id is the integer ID of the Subcorpus from its own DB table.
 * 
 * (As per above, that's what we're AIMING for - not what is true now!)
 * 
 * 
 * 
 * 
 * A DEFINITION OF THE SERIALISATION FORMALISMS
 * --------------------------------------------  
 * 
 * The Query Scope serialisation
 * -----------------------------
 * 
 * If a Query Scope refers to the whole corpus, it serialises to an EMPTY STRING.
 * 
 * If a Query Scope refers to a Restriction, then its serialisation is the SAME AS THAT OF THE RESTRICTION.
 * 
 * If a Query Scope refers to a saved Subcorpus, then its serialisation is a string containing 
 * the integer ID of the Subcorpus, represented as a decimal wihtin the string, which therefore will match 
 * the regular expression ^\d+$    ....
 * 
 * There is one special case here: when a record is preserved of a Query Scope that refers to a Subcorpus
 * which is subsequently deleted, the special string "Ð" (capital eth) can be used to indicate this. 
 * 
 * So, if a query is logged with "23" as its scope, meaning that it was execuited within the subcorpus 
 * with ID 23; and if Subcorpus no. 23 is subsequently deleted; then the scope field in the Query History 
 * should no longer contain "23" but rather the literal two-byte string "\xc3\x90" (capital eth).
 * 
 * The Subcorpus serialisation (Item List)
 * ---------------------------------------
 * 
 * A Subcorpus serialises to a representation either of a Restriction that defines it; or of a list of the 
 * items that it contains. In the former case, the serialisation is that of the Restriction in question.
 * 
 * Let's consider the latter case here - that of a list (of items, whatever an "item" is in context).
 * 
 * All complex serialisations, that is, other than the subcorpus pointers, start with a magic number :
 * a single byte that indicates how the rest is to be interpreted. (In fact, even the eth-for-delete
 * can be seen as having a magic number: \xc3. 
 * 
 * The magic number for a list is ^. This is also the field delimiter within the format. There are 3 fields,
 * as follows:
 * 
 * ^ATT_FAMILY^ATTRIBUTE^LIST_OF_IDS
 * 
 * ATT_FAMILY here is a top-level XML element (like <u> or <text>).
 * 
 * ATTRIBUTE is the subsidiary s-attribute, derived from the family attribute, that contains the ID codes. 
 * It must therefore have the datatype METADATA_TYPE_UNIQUE_ID. (It can also have the datatype 
 * METADATA_TYPE_IDLINK, in which case the list is a slightly different kettle of fish: see below.)
 * 
 * The LIST_OF_IDS is space-delimited. The items in it must be sorted (according to binary collation). 
 * There is no space, nor another ^, after the final item on the list. 
 * 
 * So, for instance, if we have <u id="AAA01">..... then we could have
 * 
 * ^u^id^AAA01 AAA02 ABB12 ACD04
 *
 * Normally, we would expect the IDs to be values on the s-attribute in question.
 * 
 * However, if it is "^text^id^..." then that's a special case - we know that the IDs are to be found 
 * in the text_metadata table, along with the necessary cpos.
 * 
 * That's the case where an s-attribute of type METADATA_TYPE_UNIQUE_ID is specified. What about
 * a METADATA_TYPE_IDLINK? In that case, the list contains not IDs for individual items, but IDs
 * from the linked table: *All* instances of the s-attribute that link to that ID get included.
 * 
 * So, for instance, coming from something like <u who="JACK">......:
 * 
 * ^u^who^ALICE BOB EVE FRED
 * 
 * ... would resolve to *all* the utterances spoken by any of those speaker (note that in this case,
 * the subcorpus's n_items field stores the size in *utterances*, not the size in *speakers*,
 * though the latter is secondarily accessible by counting the array.
 * 
 * In some cases, we have a set of items based on XML, but the individual instances do not have ID codes.
 * For example, imagine that our <u> element has a "type" attribute but no "id"; and that we create a 
 * subcorpus of <u>s using metadata restrictions, but delete some of the utterances manually, so this
 * is now a list-type subcorpus. However, there is no ID-type attribute in the family of the <u>.
 * 
 * In this case, the representation uses *sequence on the attribute* to identify the instances - encoded
 * as decimal integers (and sorted numerically ascending - NOT a binary string sort) corresponding to
 * the sequence position, i.e. the order they would come out of cwb-s-decode. So the first is 0, the
 * second is 1, and so on. In this case, the second field is empty. Here's an example:
 * 
 * ^u^^0 1 14 27 51 52 53 101 123 179
 * 
 * Note that, unlike in the dump-file we will generate, adjacent ranges are not merged in a text list of
 * this kind. (The representation doesn't actually allow for that.)
 * 
 * Finally. If a subcorpus is made up simply of arbitrary cpos pairs (e.g. because it was based on 
 * an upload...) then all three fields are empty: the "content" is in the dumpfile, the n_items field
 * will say how many cpos pairs there are, and nothing can be said of its makeup, except "it's the bits of 
 * the corpus from here to there, here to there, and here to there."
 * 
 * The "content" is then just:
 * 
 * ^^^
 * 
 * (note we keep all three to make life easy should we ever apply the explode/unshift pattern to it!)
 * 
 * When the Subcorpus object is asked what kind of "item" is contains, it reports the contents of the 
 * FIRST field - that is, the attribute-family (incl. "text", if this is texts). IF the first field is 
 * empty, it returns empty. The caller needs to interpret that.
 *  
 * Because a Subcorpus serialisation can contain a full list, which can be long, its MySQL storage
 * is a MEDIUMTEXT. All the other serialisations discussed here are more compact, and live in only a 
 * standard TEXT field.
 * 
 * The Restriction serialisation (conditions and combinations of conditions)
 * -------------------------------------------------------------------------
 * 
 * Because they may be used as Subcorpus content alternatively to an Item List, these serialisations use
 * a clearly distinct magic number - well, actually, two of them. 
 * 
 * A Restriction may involve criteria selecting ranges from one s-attribute family, or more than one.
 * (Where "text", although a special case, counts as an s-attribute family.... but one whose
 * "children" are found in the text_metadata table, rather than in the CWB index.)
 * 
 * If the criteria all relate to just one s-attribute family (say, <u>), then the Restriction 
 * is equal to a set of complete ranges of that s-atrtribute: it contains the whole of the set of
 * <u>-to-</u> ranges that satisfy those criteria.
 * 
 * However, if the criteria relate to more than one s-attribute family (say, <u> and <s>; or <u>
 * and the special "text") then we can make no assumptions about anything. In this case, the Restriction
 * is equal to some collection of cpos pairs, representing that slice of the corpora that is selected
 * by ALL the criteria. (We cannot even assume nesting of everythihng other than <text> within <text>:
 * there is nothing in the indexing rules to stop people using XML elements that are bigger than <text>
 * or that end halfway throught <text>.)
 * 
 * The magic number distinguishes these.
 * 
 * The magic number if there are conditions on either just texts, or just one family of elements, is @.
 * 
 * The magic number if there are conditions on multiple elements, or text plus an element, is $.
 * 
 * In either case, condition-sets are delimited with a ^. So the overall format is
 * 
 *    $^CONDITION_SET
 * 
 * or
 * 
 *    @^CONDITION_SET^CONDITION_SET^CONDITION_SET^CONDITION_SET
 * 
 * (the extra delimiter ^ in the 2nd byte is to make the magic number a separate slot and to make life
 * easy when using explode. Note re-use of the ^ delimiter, which is safe because this can never get 
 * confused with an item list.)
 * 
 * The condition sets must be ordered in ascending order of the (binary) sort of their serialised form.
 * Since each condition set begins with the element it applies to this measn we are effectively sorting on
 * the field.
 * 
 * Each condition set refers to a specific metadata field, and contains a set of values: the field must match
 * one of them. The field is EITHER an s-attribute with a value, OR a column in a table if the datatype is
 * IDLINK.
 * 
 * Here's how a condition set is structured:
 * 
 *    ELEMENT|FIELD~VALUE.FIELD~VALUE.FIELD~VALUE
 * 
 * where: 
 * 
 * ELEMENT is either the special flag "--text" or the name of the s-attribute; 
 * FIELD specifies what attribute, or IDLINK'ed; column, the condition aopplies to; 
 * and VALUE is what the field must match.
 * 
 * If the same FIELD is specified multiple times, that's an OR condition. 
 * If different fields are specified, that's an AND condition across those fields. 
 * 
 * If a field is of type IDLINK, then there needs to be a further specification of the column in the
 * linked table that is to be addressed. This is done by adding / and then the column name.
 * 
 * ***AT THE MOMENT***, multiple layers of indirection are not allowed. An idlink'ed table cannot itself
 * contain an IDLINK column. The exception is text metadata, which is basically an idlink table, but
 * which is allowed to contain IDLINK columns. That is the reason for the special flag with "--text".
 * 
 * The field-value pair may be an empty string; in that case, we assume we are matcvhing for "empty stirng"
 * (not currently allowed for CLASSIFICATION, but in future it might be). By contrast, if we want merely
 * to assert that we are restricting the query to cpos within that s-attribute, we leave off
 * the | (in which case there can be no further conditions, obviously.)
 * 
 * The field-value pairs must be given in (binary) sort-order of their serialisation. 
 * 
 * The format of VALUE depends on the datatype of the field being addressed. Similarly, the rules for "how
 * to work out whether a given value matches" are dependent on the datatype. 
 * 
 * AT THE MOMENT, we only support the following data types: UNIQUE_ID and CLASSIFICATION, with DATE on the horizon.
 * (IDLINK is supported but in this context is part of the field address, not a VALUE; FREETEXT is designed
 * to not be available for restricted querying, ergo not here on purpose.) Bothn UNIQUE_ID and CLASSIFICATION
 * use string equality: where the value in the field matches the VALUE supplied, that's in; any deviation
 * at all and it is out. DATE values will use the database formalism for dates: -yyyy_mmdd or +yyyy_mmdd; a pair
 * is supplied as a VALUE, and a match is found for any date between the two points, INCKL) 
 * 
 * OK, time for some examples. Let's look, to begin with, at some cases where there is only one condition set.
 * 
 * A really basic condition: contents of all <heading> elements.
 * 
 *    $^heading
 * 
 * A condition which  that a particular member of the <head> family must have a particular (classification)
 * value.
 * 
 *    $^heading|rend~bold
 * 
 * Same again, but with multiple conditions (rend must be in category "bold" OR "it"; font must be in 
 * category "HUGE"). 
 * 
 *    $^heading|font~HUGE.rend~bold.rend~it
 * 
 * A text-metadata example (oldstyle!)
 * 
 *    $^--text|genre~A.genre~B.period~modern.period~postmodern
 * 
 * An example of text-metadata with an IDLINK. We assume the AUTHOR column contains an IDLINK, so we can specify
 * an author who is 545 to 60, in class C2 or DE, and male.
 * 
 *    $^--text|author/age~45_60.author/class~C2.author/class~DE.author/sex~m
 * 
 * Now for that same person specification, but applied to a <u who="...."> :
 * 
 *    $^u|who/age~45_60.who/class~C2.who/class~DE.who/sex~m
 * 
 * Now some examples with multiple condition sets. Note because of the binary order rule, --text always comes
 * first.
 * 
 * Restricts to cpos within <heading> in texts where genre is AAA.
 * 
 *    @^--text|genre~AAA^heading|
 * 
 * Restricts to female Geordie speakers in utterances flagged as exclamations in texts recorded in 2010 or 2009.
 * 
 *    @^--text|recyear~2009.recyear~2010^u|type~excl.who/sex~f.who/variety~geordie
 * 
 * Note that in none of these examples are we dealing with overlaps - but we easily could be - so when this is 
 * actually converted to cpos, then the resault is an arbitrary collection of pairs.
 * 
 * And that, I believe, is all.
 * 
 * The description of how these things are passed around in URLs can be found in the comments on the relevant
 * methods in the Restriciton class. Briefly: if the Restriction (or Subcorpus or Query Scope) is being passed 
 * around from a form the user interacts with, a slightly more complex format is used. If it is being passed 
 * around in a unitary manner, then it is fine just to pass the serialisation as a single HTTP parameter.
 */

/*
 * A MASSIVE AND IMPORTANT TODO FOR RESTRICTIONS
 * =============================================
 * 
 * (and Subcorpora based on Restrictions)
 * 
 * I forgot, while coding its current state (as of v 3.2.7) that the table
 * xml_metadata_values (and, in theory, idlinked tables) actually contain 
 * per-category word-and-segment counts.
 * 
 * So, when there is only one-type-of-s-attribute, we can find out the size
 * from the xml_metadata_values table.
 * 
 * Should've remembered that!!!!
 * 
 * TODO
 * TODO
 * TODO
 * 
 */

/**
 * This class represents a Query Scope. It contains the string serialisation, plus 
 */
class QueryScope
{
	/** A QueryScope is EMPTY when it is based on a Restriction satisfied by no tokens: that is, there is no part of the corpus 
	 * that satisifies all the specified conditions, and therefore the query cannot run. */
	const TYPE_EMPTY        = -1;
	/** A QueryScope is WHOLE_CORPUS when we are searching within the entire corpus: there is no Restriction and no Subcorpus. */
	const TYPE_WHOLE_CORPUS =  0;
	const TYPE_RESTRICTION  =  1;
	const TYPE_SUBCORPUS    =  2;
	
	/** 
	 * The special QueryScope serialisation for the Query History table that indicates that the Scope
	 * of the query was a subcorpus that has subsequently been deleted.
	 * 
	 * It's a public static var rather than a consonant for your ease of embedding. 
	 * Because I'm just a nice person that way. You can thank me later.
	 */
	public static $DELETED_SUBCORPUS = "\xc3\x90";
	
	/* ------------------------------------------------------------------- */
	
	/** variable that indicates which type of scope this is. */
	public $type;
	
	/** if this is a Restriction the object goes here. */
	private $restriction;
	
	/** if this is a Subcorpus the object goes here. */
	private $subcorpus;
	
	/** string containing the serialised version of this query scope */
	private $serialised;
	

	public function __construct()
	{
		/* do nothing */
	}
	
	
	/**
	 * Creates a Query Scope object by parsing an HTTP query string (or fragment), such as that 
	 * found in $_SERVER[QUERY_STRING].
	 * 
	 * @param string $url_query   The input: post-? query string from a URL (or the full URL). 
	 * @param bool $scrub_GET     If true, the $_GET superglobal and $_SERVER[QUERY_STRING] will have
	 *                            the fields used by this function removed. Fasle by default, normally 
	 *                            set to true if the first paramter is $_SERVER[QUERY_STRING]. 
	 * @return QueryScope         The new object.
	 */
	public static function new_from_url($url_query, $scrub_GET = false)
	{
		$o = new self();
		$o->parse_from_url($url_query, $scrub_GET);
		return $o;
	}
	
	
	/**
	 * Creates a Query Scope object from a serialisation string.
	 * 
	 * @param string $input  A serialisation string to unserialise. 
	 * @return QueryScope    The new object.
	 */
	public static function new_by_unserialise($input)
	{
		$o = new self();
		$o->serialised = $input;
		$o->parse_serialisation();
		return $o;
	}
	
	/**
	 * This func EITHER extracts a subcorpus, OR dispatches to the Restriction obj for parsing.
	 * 
	 * If the 2nd arg is true, then the _GET keys parsed for the query scope will be deleted from
	 * all the usual global places (to prevent their being passed on to other scripts).
	 * 
	 * So, set 2nd arg to true if this is parsing a URL string from the _SERVER array;
	 * set it to false (which is default) if it's just some random link chunk....
	 * 
	 * The 1st arg is expected to be (possibly) URL encoded.
	 */
	private function parse_from_url($url_query, $scrub_GET = false)
	{
		/* initial conditions: whole corpus. May be disproven below. */
		$this->serialised = '';
		$this->type = self::TYPE_WHOLE_CORPUS;

 		$url_query = urldecode($url_query);

		/* Now, check for restrictions and subcorpus statements. 
		 * 
		 * Note that the specification of a subcorpus trumps the specification of restrictions
		 * (of course, a query submitted from the CQPweb forms will only have ONE of these,
		 * not both, so this is just a safety measure in the face of badly-formed links.
		 * 
		 * Note, the reload action for "last rextrictions" used to be its own function, but
		 * here it is all just done within the if-else ladder. 
		 */
		if ( false !== strpos($url_query, '&t=--last_restrictions') ) 
		{
			/* query is within scope of "last restrictions" */
			global $User;
			global $Corpus;
			/* using these global would normally be dicey, but the very nature of
			 * "last restriction" is that it is mutable across corpus/user combos. */ 
		
			$sql = "SELECT restrictions from saved_subcorpora
				WHERE name = '--last_restrictions'
				AND corpus = '{$Corpus->name}'
				AND user = '{$User->username}'";
			$result = do_mysql_query($sql);
			
			if (0 == mysql_num_rows($result))
				/* there are no "last restrictions": default back to "whole corpus". */
				return;
			else
			{
				list($this->serialised) = mysql_fetch_row($result);
				$this->type = self::TYPE_RESTRICTION;
				$this->restriction = Restriction::new_by_unserialise($this->serialised, $Corpus->name);
			}
		}
		else if (preg_match('/&t=~sc~(\d+)&/', $url_query, $m))
		{
			/* query is within a subcorpus. */
			$this->subcorpus = Subcorpus::new_from_id($m[1]);
			$this->serialised = $m[1];
			$this->type = self::TYPE_SUBCORPUS;
		}
		else
		{
			/* query scope needs to be parsed out as a restriction */
			if (false === ($r = Restriction::new_from_url($url_query, true)))
				/* no parseable restrictions; ergo, default back to "whole corpus". */
				return;
			$this->restriction = $r;
			$this->serialised = $this->restriction->serialise();
			if (0 == $this->restriction->size_tokens())
				$this->type = self::TYPE_EMPTY;
			else
				$this->type = self::TYPE_RESTRICTION;
		}
		
		if ($scrub_GET)
		{
			clear_http_parameter('t');
			clear_http_parameter('del');
		}
	}

	
	/**
	 * Breaks apart a serialisation string and analyses it to set up the various variables.
	 */
	private function parse_serialisation()
	{
		if ('' === $this->serialised)
		{
			/* this Scope is over the WHOLE CORPUS */
			$this->type = self::TYPE_WHOLE_CORPUS;
			/* and, therefore, there is no need to do anything else ... */
		}
		else if (self::$DELETED_SUBCORPUS == $this->serialised)
		{
			/* only in query history... */
			$this->type = self::TYPE_SUBCORPUS;
			/* but we do nothing else. */
		}
		else if (preg_match('/^\d+$/', $this->serialised))
		{
			/* this Scope refers to a SUBCORPUS */
			$this->type = self::TYPE_SUBCORPUS;
			$this->subcorpus = Subcorpus::new_from_id((int)$this->serialised);
		}
		else
		{
			/* ANYTHING ELSE: this Scope refers to a Restriction */
			$this->type = self::TYPE_RESTRICTION;
			$this->restriction = Restriction::new_by_unserialise($this->serialised);
		}
	}
	
	/**
	 * Gets a string that can go to the database.
	 */
	public function serialise()
	{
		return $this->serialised;
	}
	
	/**
	 * Gets a string that can go in a link.
	 * 
	 * Whole corpus - nothing. Subcorpus - link of style /&t=~sc~(\d+)&/ . Restriction - call tis function.
	 * 
	 * And in whatever case, wrapped up in a big "del=begin......&del=end". Note, no leading &.
	 */
	public function url_serialise()
	{
		switch($this->type)
		{
		case self::TYPE_WHOLE_CORPUS:
			return 'del=begin&del=end';
		case self::TYPE_SUBCORPUS:
			return 'del=begin&t=~sc~' . $this->serialised . '&del=end';
		case self::TYPE_RESTRICTION:
			return $this->restriction->url_serialise();
		}
	}
	
	
	/**
	 * Returns a description of the scope suitable for printing in a solution heading;
	 * if the sciope is the whole corpus, an empty value is returned.
	 * 
	 * Can be HTMl or text according to the argument. 
	 */
	public function print_description($html = true)
	{
		switch ($this->type)
		{
		case self::TYPE_WHOLE_CORPUS:
			return '';
		case self::TYPE_RESTRICTION:
			return 'restricted to ' . $this->restriction->print_as_prose($html);
		case self::TYPE_SUBCORPUS:
			return 'in subcorpus ' . ($html?'&ldquo;<em>':'"') . $this->subcorpus->name . ($html?'</em>&rdquo;':'"');
		}
	}

	
	/**
	 * Saves the Restriction within this object as the Last Restriction, if a restriction was used.
	 */
	public function commit_last_restriction()
	{
		if ($this->type == self::TYPE_RESTRICTION)
		{
// 			global $Corpus;
// 			global $User;
// 			$lr = Subcorpus::create_last_restrictions($this->restriction, $Corpus->name, $User->username);
// 			$lr->save();
			$this->restriction->commit_last_restriction();
		}
	}
	
	


	/**
	 * 
	 * Returns false if we can't intersect at the present time.
	 * 
	 * NB the returned object may not be unique!!!!
	 * 
	 * @param QueryScope $other_scope   The second scope to use for intersection.
	 */
	public function get_intersect($other_scope)
	{
		if ($other_scope->type == QueryScope::TYPE_WHOLE_CORPUS)
			return $this;
		switch ($this->type)
		{
		case QueryScope::TYPE_WHOLE_CORPUS:
			return $other_scope;
			
		case QueryScope::TYPE_SUBCORPUS:
			//TODO this is likewise v sketchy
			if ($other_scope->type == QueryScope::TYPE_RESTRICTION)
				return $this->subcorpus->get_intersect_with_restriction($other_scope->restriction);
			else
				return false;
			
		case QueryScope::TYPE_RESTRICTION:
			//TODO this is hugely sketchy
			// and is repeated in the Subcorpus method above!!!
			// which suggests that whatever it is ultiamtely should be in thwe Restrioction class.
			if (
				preg_match('/^\$\^--text\|/', $set1 = $this->restriction->serialise()) 
				&& 
				preg_match('/^\$\^--text\|/', $set2 = $other_scope->restriction->serialise())
			)
			{
				/* both based on text metadata : we can get an intersection. */
				$cats1 = explode('.', substr($set1, 8));
				$cats2 = explode('.', substr($set2, 8));
				$all = array_unique(array_merge($cats1, $cats2));
				sort($all);
				$newinput = '$^--text|' . implode('.', $all);
				return QueryScope::new_by_unserialise($newinput);
			}
			else 
				return false;
		}
		
		return false;
	}
	
	
	
	/*
	 * ==================
	 * DELEGATION METHODS
	 * ==================
	 * 
	 * These methods just pass through to the equivalent Restriction/Subcorpus methods.
	 * 
	 * Only a few exist at the moment; more will be added as we need them.
	 */
	
	
	
	
	/**
	 * Activate the limits imposed by this Query Scope in CQP.
	 * 
	 * This function delegates to the like-named methods in the Subcropus and Restriciton classes. 
	 */
	public function insert_to_cqp()
	{
		if ($this->type == self::TYPE_SUBCORPUS)
			$this->subcorpus->insert_to_cqp();
		else if ($this->type == self::TYPE_RESTRICTION)
			$this->restriction->insert_to_cqp();
	}
	
	/**
	 * Assures the existence of this scope's dumpfile and returns the path.
	 * 
	 * In the case of a Restriction, the dumpfile will be created in the same palce that Subcorpus dumpfiles
	 * live -- i.e. $Config->dir->cache.
	 * @param bool $file_is_temporary  Out-param: will be set to true if the file is one that the caller should delete, 
	 *                                 otherwise will be set to false.
	 */
	public function get_dumpfile_path(&$file_is_temporary = false)
	{
		$file_is_temporary = false;
		
		if ($this->type == self::TYPE_SUBCORPUS)
			return $this->subcorpus->get_dumpfile_path();
		else if ($this->type == self::TYPE_RESTRICTION)
		{
			global $Config;
			$path = "{$Config->dir->cache}/rsdf-{$Config->instance_name}";
			while (file_exists($path))
				$path .= chr(mt_rand(0x41,0x5a)); 
			$this->restriction->create_dumpfile($path);
			$file_is_temporary = true;
			return $path;
		}
		
		/* should not be reached: this func should not be called on a whole-corpus scope. */
		assert (false, "Point not reached was reached -- QueryScope::get_dumpfile_path"); // rempve this once we're happy about this logic.
		return false;
	}
	
	
	/**
	 * Get the freqtable record (associative array) for this Query Scope's internal Subcorpus or Restriction. 
	 * 
	 * Returns false if there isn't one, or if this Scope is Whole Corpus. 
	 */
	public function get_freqtable_record()
	{
		switch($this->type)
		{
		case self::TYPE_SUBCORPUS:
			return $this->subcorpus->get_freqtable_record();
		case self::TYPE_RESTRICTION:
			return $this->restriction->get_freqtable_record();
		default:
			return false;
		}
	}
	
	
	
	/**
	 * Integer values of size in tokens.
	 */
	public function size_tokens()
	{
		switch ($this->type)
		{
		case self::TYPE_WHOLE_CORPUS:
			return get_corpus_wordcount();
			
		case self::TYPE_RESTRICTION:
			return $this->restriction->size_tokens();
			
		case self::TYPE_SUBCORPUS:
			return $this->subcorpus->size_tokens();
		}
	}
	
	
	/**
	 * Integer value of size in items. You can get the type of item as an out-parameter.
	 */
	public function size_items(&$item = NULL)
	{
		switch ($this->type)
		{
		case self::TYPE_WHOLE_CORPUS:
			$item = 'text';
			return get_corpus_n_texts();
			
		case self::TYPE_RESTRICTION:
			return $this->restriction->size_items($item);
			
		case self::TYPE_SUBCORPUS:
			return $this->subcorpus->size_items($item);
		}
	}
	
	
	/** Gets list of items in the subcorpus or retriction. Out parameters tell you what type of item it is. Returns false if we can't get a list of items.*/
	public function get_item_list(&$item_type = NULL, &$item_identifier = NULL)
	{
		switch ($this->type)
		{
		case self::TYPE_RESTRICTION:
			return $this->restriction->get_item_list($item_type, $item_identifier);
			
		case self::TYPE_SUBCORPUS:
			return $this->subcorpus->get_item_list($item_type, $item_identifier);
		}
		return false;
	}
	
	/** Gets the name of the corpus in which the Query Scope (Restriction or Subcorpus) exists. */
	public function get_corpus()
	{
		switch ($this->type)
		{
		case self::TYPE_WHOLE_CORPUS:
			/* we probably ought never to get here. */
			assert (false, "QueryScope has reached an unanticipated branch -- please report this as a logic bug!!!!");
			global $Corpus;
			return $Corpus->name;
		case self::TYPE_RESTRICTION:
			return $this->restriction->get_corpus();
		case self::TYPE_SUBCORPUS:
			return $this->subcorpus->corpus;
		}
		return false;
		
	}
	
	
	
	/**
	 * Returns the size of the intersection between this QueryScope and a specified set of reductions.
	 * 
	 * For the moment, the reductions are passed in as an array of estra filters: that is, an array of "class~cat" strings
	 * (this is the format used internally by distribution postprocesses.) 
	 * 
	 * Longetrm-TODO : this will change as we have more rigorous intersection methods. 
	 * 
	 * This func returns false if the QueryScope is not a complete set of texts. 
	 * 
	 * 
	 * TODO
	 * TODO
	 * TODO
	 * TODO: note this was created before I realised the need for a comprehensive set of intersect functions..............
	 * now we have those, it might be more efficient to use them.
	 * TODO
	 * TODO
	 * TODO
	 * 
	 * 
	 * Otherwise return an array: 0=>size in tokens, 0>size in texts.
	 */
	public function size_of_classification_intersect($category_filters)
	{
		global $Corpus;
		
		switch($this->type)
		{
		case self::TYPE_SUBCORPUS:
			/* delegate to Subcorpus */
			return $this->subcorpus->size_of_classification_intersect($category_filters);
			
		case self::TYPE_RESTRICTION:
			/* delegate to Restriction */
			return $this->restriction->size_of_classification_intersect($category_filters);
			
		case self::TYPE_WHOLE_CORPUS:
			/* if whole corpus, then return the size of the set of texts indicated by the extra-catgeogry-restrictions arg. */
			
			foreach($category_filters as &$e)
				$e = preg_replace('/(\w+)~(\w+)/', '(`$1` = \'$2\')', $e);
			$filter_where_conditions = implode(" && ", $category_filters); 
			$where = empty($filter_where_conditions) ? '' :  "where " . $filter_where_conditions;
			// NB the above code is repeated in Restriction && Subcropus; but this is a temp fix, so no worries. 
			
			return mysql_fetch_row(do_mysql_query("select sum(words), count(*) from text_metadata_for_{$Corpus->name} $where")) ; 
		}
		return false;
	}
	
	
	/**
	 * Printable string for size in items.
	 */
	public function print_size_items()
	{
		switch ($this->type)
		{
		case self::TYPE_WHOLE_CORPUS:
			assert(false, "Please report a Critical Logic Bug:: QScope has executed code that we thought was not reached. ");
			return number_format(get_corpus_n_texts());
			
		case self::TYPE_RESTRICTION:
			return $this->restriction->print_size_items();
			
		case self::TYPE_SUBCORPUS:
			return $this->subcorpus->print_size_items();
		}
	}
	
	
	public function print_size_tokens()
	{
		switch ($this->type)
		{
		case self::TYPE_WHOLE_CORPUS:
			assert(false, "Please report a Critical Logic Bug:: QScope has executed code that we thought was not reached. ");
			global $Corpus;
			return number_format($Corpus->size_tokens);
			
		case self::TYPE_RESTRICTION:
			return $this->restriction->print_size_tokens();
			
		case self::TYPE_SUBCORPUS:
			return $this->subcorpus->print_size_tokens();
		}
	}
	
	
}
/* 
 * =======================
 * end of class QueryScope 
 * =======================
 */





/**
 * This class represents a Restriction : a set of conditions that reduce a query scope.
 */
class Restriction
{
	/** regex for a valid condition : FIELD~VALUE. Note this currently only supports CLASSIFICATION/IDLINK, not DATE. */
	const VALID_CONDITION_REGEX = '/(\w+(?:\/\w+)?)~(\w+)/';
	
	/*
	 * The following variables store the actual content of the restriction.
	 * 
	 * The canonical variable is the serialisation (DB format). 
	 * 
	 * Unlike a Subcorpus, a Restriction is always coded as a single string column in the database:
	 * it is never an actual table-row.
	 */
	
	/** string containing the serialised version of the Restriction - stored in the DB. */
	private $serialised;
	
	/** array containing the structured breakdown of the serialisation. */
	private $parsed_conditions;
	
	/** if text-metadata conditions are present in this restriction, then the 
	 * where-clause to be used on the text_metadata table will be placed here. */
	private $stored_text_metadata_where;
	
	/** if idlink-based conditions are present in this restriction, then the 
	 * where-clause to be used on the relevant idlinked table will be placed here;
	 * it's an array where the idlink attribute (full, i.e. like "u_who") is the key. */
	private $stored_idlink_where;
	
	/** Contains the collected cpos for this Restriction; an array of arrays where each inner array has a [begin,end] pair at keys 0 and 1. */
	private $cpos_collection = NULL;
	
	/** corpusname that this belongs to (prob wee could get away for now using the global $Corpus, but let's be future-proof) */
	private $corpus;
	
	/** string indicating the type of item of which this Restriction consists. 
	 * If it consists of conditions on multiple types of item, it contains the arbitrary string '@'. */
	private $item_type;
	/* we may need aanother variable indicating whetehr it is text-metadata / one-xml-tag-based / mixed. */
	
	/** number of items in the Restriction. */
	private $n_items;
	
	/** number of tokens in the Restriction. */
	private $n_tokens;

	/** Freqtable record (an stdClass from the database). Set to NULL when this has not been checked yet. Set to false if there is no table. */
	private $freqtable_record = NULL;

	
	/** whether we have run the internal text metadata where-clause setup */
	private $hasrun_initialise_text_metadata_where = false;
	
	/** whether we have run the internal idlink where-clause setup */
	private $hasrun_initialise_idlink_where = false;
	
	/** whether we have run the internal array-of-cpos setup */
	private $hasrun_initialise_cpos_collection = false;
	
	/** whether we have run the internal size-fo-restriction setup */
	private $hasrun_initialise_size = false;
	
	
	/** whether the internal data (cpos cokllection, sizes) should be added to the restriction cache 
	 * (assume not until some function runs that implies it needs to be!) */
	private $needs_to_be_added_to_cache = false;
	
	/*
	 * ================
	 * CREATION METHODS
	 * ================
	 */
	
	public function __construct()
	{
		/* do nothing */
	}
	
	/**
	 * Create a new restriction by unserialising a serialised Restriciton (usually from the DB). 
	 * 
	 * @param string $input    Database format string to unserialise. If not supplied,
	 *                         the global corpus is used.
	 * @param string $corpus   If not supplied, will be deduced from environment.
	 * @return Restriction     The resulting object, or false if parsing of the argument failed.
	 *                         Parse fail might mean: input string is poorly formed, or defines
	 *                         a section of the corpus that is completely empty (zero tokens). 
	 */
	public static function new_by_unserialise($input, $corpus = NULL)
	{
		if (empty($input))
			return false;
		$o = new self();
		$o->set_corpus($corpus);
		$o->serialised = $input;
		if (! $o->parse_serialisation())
			return false;		
		$o->retrieve_from_cache();
		if (! $o->hasrun_initialise_size)
			$o->initialise_size();
		/* BEFORE RETURNING: call the commit method, which will take effect 
		 * iff the correct var was set to "true" in the init functions. */
		$o->commit_to_cache();
		return $o;
	}
	
	
	
	/**
	 * Create a new restriction from a URL-format string. 
	 * 
	 * @param string $url_query  String with (part of) a URL query (e.g. that stored in $_SERVER[QUERY_STRING] )
	 *                           from which the Restriction details can be scraped.
	 * @param bool $decoded      Defaults to false: iff true, it is assumed that the first argument does
	 *                           not need to be URL-decoded.  
	 * @param string $corpus     If not supplied, will be deduced from environment.
	 * @return Restriction       The resulting object, or false if parsing of the argument failed.
	 */
	public static function new_from_url($url_query, $decoded = false, $corpus = NULL)
	{
		$o = new self();
		$o->set_corpus($corpus);
		if (! $o->parse_from_url($url_query, $decoded))
			return false;
		$o->retrieve_from_cache();
		if (! $o->hasrun_initialise_size)
			$o->initialise_size();
		/* BEFORE RETURNING: call the commit method, which will take effect 
		 * iff the correct var was set to "true" in the init functions. */
		$o->commit_to_cache();
		return $o;
	}
	
	
	/**
	 * Breaks apart a serialisation string and analyses it to set up the various variables.
	 */
	private function parse_serialisation()
	{
		/* note that this function is the definitive referfence on "what does it mean" for the various 
		 * flags within the internal data structure $this->parsed_conditions .... */
		
		$parts = explode('^', $this->serialised);

		/* should be at least two sections. */
		if (2 > count($parts))
			return false;
		
		$this->parsed_conditions = array();

		$start_flag = $parts[0];
		unset($parts[0]);
		
		/* parse the parts */
		foreach ($parts as $cset)
		{
			if (false === strpos($cset, '|'))
				/* this condition is simply of the must-appear-in type. */
				$this->parsed_conditions[$cset] = '~~~within';
			else
			{
				/* this is a condition on text metadata; OR, this is a condition on something xml ish; 
				 * we don't parse further, or do anything different for the two cases; we might decide to later. */ 
				list($s_att, $conditionlist) = explode('|', $cset);
				$this->parsed_conditions[$s_att] = explode('.', $conditionlist);
				/* so each member of p_c[[s_att] is a list of FIELD~VALUE strings applied to that s_att. */
			}	
		}
		
		/* parse the item type */
		if ($start_flag == '$')
		{
			/* this is a SINGLE CONDITION SET. */
			foreach($this->parsed_conditions as $k => $v)
			{
				$this->item_type = $k;
				/* the special case with magic.... */
				if ($this->item_type == '--text')
					$this->item_type = 'text';
				break;
			}
		}
		else if ($start_flag == '@')
			/* multiple conditions sets */
			$this->item_type = '@';
		else
			return false;
		
		return true;
	}
	
	
	
	
	/**
	 * Returns true if parse OK, false if the string could not be parsed
	 * (in which case the object is in an unknown state).
	 * 
	 * @param string $url_query  String to parse: a (bit of a) URL, which may possibly contain url-encoded bits.
	 *                           Normally comes from $_SERVER['QUERY_STRING'].
	 * @param bool $decoded      Defaults to false: iff true, it is assumed that the first argument does
	 *                           not need to be URL-decoded.  
	 */
	private function parse_from_url($url_query, $decoded = false)
	{
		/*
		 * HOW IT WORKS: the condition-pairs that apply are present in the URL-chunk. 
		 * 
		 * We analyse the string and composed it to a set of conditions. This is set up in the relevant 
		 * internal data structures, but we also run the setup-serialisation so that the serialised string is ready too.
		 * 
		 * False is returned only if the string contains something unparseable, or if we fail to find something we need to find.
		 * If false is returned the object's internals are in an incomplete state.
		 * 
		 * Within the URL-string, restrictions are represented so: between &del=begin and &del=end, there are a series of
		 * &t= units. Each one of these represents a clicked restriction on the form. There is no guarantee from the input
		 * form what order these will be in, unlike a serialised restriction, which has a canonical order. 
		 * 
		 * The &t-units are very similar to field-value pairs in the serialisation string, except that the full field has 
		 * to be repearted. So for instance, this serialisation string
		 * 
		 *    $^heading|font~HUGE.rend~bold.rend~it
		 *    
		 * would derive from this set of t-units:
		 * 
		 *    &t=heading|font~HUGE&t=heading|rend~bold&t=heading|rend~it
		 * 
		 * (and again, we do not allow any assumptions about the order these things will occur in). The fact tghat 
		 * 
		 * We use an abbreviation for the magical "--text" since text metadata is the most common type of restriction:
		 * 
		 *    $^--text|genre~A
		 * 
		 * would derive from
		 *  
		 *    &t=-|genre~A 
		 *    
		 * (which is very similar to the old style before the big rewrite).
		 * 
		 * The basic condition "must occur in....." is rendered as follows:
		 * 
		 *    &t=heading
		 *    
		 * And when there is an IDLINK, it looks like this (big example from the intro):
		 * 
		 *    &t=-|recyear~2009&t=-|recyear~2010&t=u|type~excl&t=u|who/sex~f&t=u|variety~geordie
		 * 
		 * which then maps to
		 *  
		 *    @^--text|recyear~2009.recyear~2010^u|type~excl.who/sex~f.who/variety~geordie
		 */
	
		/* format of the string = &del=begin(ALL THE &t= DEFINITIONS)&del=end */
		if ( ! preg_match('/[&?]del=begin(.*)&del=end/', $url_query, $m))
			return false;
	
	 	/* must be at least one restriction */
	 	if ($m[1] === '&t=' || $m[1] === '')
	 		return false;
	
		/* note that this class usually expects URL-encoded data, but can be forced to accept URL-decoded */
		if ($decoded)
			$unparsed = $m[1];
		else
			$unparsed = urldecode($m[1]);

		$restriction_bundle = preg_split('/&t=/', $unparsed, -1, PREG_SPLIT_NO_EMPTY );

		$this->parsed_conditions = array();
		
		/* extract the classification scheme-category (attribute-value) pairs */
		foreach ($restriction_bundle as $r)
		{
			if (preg_match('/^\w+$/', $r))
				/* this condition is simply of the must-appear-in type. */
				$this->parsed_conditions[$r] = '~~~within';
			else
			{
				list($s_att, $condition) = explode('|', $r);
				if (! preg_match(self::VALID_CONDITION_REGEX, $condition))
					return false;
				
				if ($s_att == '-')
					/* the special short code */
					$s_att = '--text';
				/* unlike parsing a serialised string, once we've extracted the locus, we know there is just one condition. */
				if (!isset($this->parsed_conditions[$s_att]))
					$this->parsed_conditions[$s_att] = array();
				$this->parsed_conditions[$s_att][] = $condition;
			}
		}

		/* sort the array - means that identical serialised Restrictions really will be identical */
		foreach ($this->parsed_conditions as $k => $s)
			ksort($this->parsed_conditions[$k]);
		ksort($this->parsed_conditions);
		
		/* set the item type */
		if (1 == count($this->parsed_conditions))
		{
			/* this is a SINGLE CONDITION SET. */
			foreach($this->parsed_conditions as $k => $v)
			{
				$this->item_type = $k;
				/* the special case with magic.... */
				if ($this->item_type == '--text')
					$this->item_type = 'text';
				break;
			}
		}
		else
			$this->item_type = '@';

		/* now that we have internal data structures compiled, generate our serialised string for later use */
		$this->setup_serialisation();
		
		return true;
	}
	
	
	
	
	
	private function setup_serialisation()
	{
		/* the array is already sorted so all we need to do is collapse it */
		$this->serialised = ($this->item_type == '@' ? '@' : '$');
		foreach ($this->parsed_conditions as $s_att => $cond_set)
		{
			$this->serialised .= "^$s_att";
			if ('~~~within' == $cond_set)
				continue;
			$this->serialised .= '|';
			$this->serialised .= implode('.', $cond_set);
		}
	}
	
	
	

	/** 
	 * Initialises a where clause for the text metadata table. 
	 * 
	 * This can be for a set of text-metadata conditions that are a complete Restriction,
	 * or just part of a restriction. The clause has no leading "WHERE" so it's just a boolean.
	 * 
	 * Additional OR clauses can be added afterwards if you wish.
	 */
	private function initialise_text_metadata_where()
	{
		$conds = array();
		
		foreach ($this->parsed_conditions['--text'] as $c)
		{
			if (false === strpos($c, '/'))
			{
				list($class, $cat) = explode('~', $c);
				if (!isset($conds[$class]))
					$conds[$class] = array();
				$conds[$class][] = "`$class`='$cat'";
			}
			else
			{
// TODO: I haven't implemented IDLINKS on text metadata yet. Involves innerjoin prolly?????
					//TODO
					//TODO
					//TODO
					//TODO
					//TODO
					//TODO
					//TODO
					//TODO
// and another TODO: haven't implemented DATE capacity yet. 
			}
		}
		
		if (empty($conds))
			return;
		
		$intermed_sql = array();
		
		foreach($conds as $s)
			$intermed_sql[] =  '(' . implode(' || ', $s) . ')';
		
		$this->stored_text_metadata_where = implode(' && ', $intermed_sql);
		
		$this->hasrun_initialise_text_metadata_where = true;
	}

	
	
	/**
	 * Initialises an array of where-clauses that can be applied idlink tables, one whereclause per idlink.
	 * 
	 * (Note that in the overwhelming majority of cases, a Restriction will only reference one idlink per attribute...)
	 *
	 * The regions dientified by the IDs the where-clause extracts may be the complete Restriction, or just part of it.
	 * 
	 * The clause has no leading "WHERE" so it's just a boolean. Additional OR clauses can be added afterwards if you wish.
	 * 
	 * @see Restriction::$stored_idlink_where
	 */			
	private function initialise_idlink_where()
	{
		/* the where-clause store is an array indexed by the s-attribute handle of the idlink attribute. */
		$this->stored_idlink_where = array();

		$collected = array();

		$xml_info = get_xml_all_info($this->corpus);
		foreach ($this->parsed_conditions as $el => $cond_arr)
		{
			foreach ($cond_arr as $cond)
			{
				if (false === strpos($cond, '/'))
					continue;
				list($att, $rest_cond) = explode('/', $cond);
				$att_handle = "{$el}_{$att}";
				if ($xml_info[$att_handle]->datatype == METADATA_TYPE_IDLINK)
				{
					if (!isset($collected[$att_handle]))
						$collected[$att_handle] = array();
					list($class, $cat) = explode('~', $rest_cond);
					if (!isset($collected[$att_handle][$class]))
						$collected[$att_handle][$class] = array();
					$collected[$att_handle][$class][] = "`$class`='$cat'";
					
					//
					//Longterm-TODO: note lack of DATE capacity here.  See also text metadata above.
					//
				}
				/* else do nothing: this is not idlink-related. */
			}
		}

		foreach ($collected as $handle => $conds)
		{
			$intermed_sql = array();
			
			foreach($conds as $s)
				$intermed_sql[] =  '(' . implode(' || ', $s) . ')';

			$this->stored_idlink_where[$att_handle] = implode(' && ', $intermed_sql);
		}

		$this->hasrun_initialise_idlink_where = true;
	}
						
	
	
	

	/**
	 * Note that this function is called by the "new" static methods, after they have parsed their data. 
	 * 
	 * So it ALWAYS runs. It assumes that the item_type has been set.
	 */
	private function initialise_size()
	{
		if ($this->item_type == 'text')
		{
			/* JUST text-metadata */
			
			/* we use the text metadata table : run separate function to derive the "where..." */
			if (!$this->hasrun_initialise_text_metadata_where)
				$this->initialise_text_metadata_where();
			
			$sql = "SELECT count(*), sum(words) FROM text_metadata_for_{$this->corpus} WHERE {$this->stored_text_metadata_where} ";
			$result = do_mysql_query($sql);
			list($this->n_items, $this->n_tokens) = mysql_fetch_row($result);
			
			return;
		}
		
		/* so, we now know we are dealing with either JUST conditions on  1+ XML, or a mixture */
		
		if (2 > count($this->parsed_conditions)) //TODO this will work, but can we not just test $this->item_type?
		{
			/* conditions on just one type of XML element. */

			/* let's work out what the situation actually is */
			$n_xml_classification_conds = 0;
			$seen_idlinks = array();
			foreach ($this->parsed_conditions as $cond_arr)
			{
				foreach($cond_arr as $cond)
				{
					if (false !== strpos($cond, '/'))
						list($seen_idlinks[]) = explode('/', $cond);
					else
						++$n_xml_classification_conds;
				}
			}

			if (0 < $n_xml_classification_conds && 0 < count($seen_idlinks))
			{
				/* do nothing; lack of return == go to fallback case. */
			}
			else if ($n_xml_classification_conds)
			{
				/* no idlinks : it's all classifications on (the only) xml element */
				
				if (1 == $n_xml_classification_conds)
				{
					/* if there is just 1 classification/category condition, we have this element cached in  the XML metadata table */
					
					/* set up vars */
					foreach ($this->parsed_conditions as $el => $cond_arr)
					{
						list($cond) = $cond_arr;
						list($field, $match) = explode ('~', $cond);
					}
					
					$sql = "SELECT category_num_segments, category_num_words FROM xml_metadata_values 
								WHERE corpus = '{$this->corpus}'
								AND att_handle = '{$el}_{$field}'
								AND handle = '$match'";
					
					$result = do_mysql_query($sql);
					list($this->n_items, $this->n_tokens) = mysql_fetch_row($result);
					
					return;
				}
					
				/* IF  WE HAVE GOT TO HERE:
				 * 
				 * There are multiple classification-type conditions on the XML element. 
				 * 
				 * That means we need to stream through the s-attribute: which means using initialise_cpos_collection.
				 * 
				 *  So we just proceed to the cpos collection, as do all the non-return branches.
				 */
			}
			else
			{
				/* it's all idlinks. */
				
				/* is all the same IDlink? */
				if (1 == count($seen_idlinks))
				{
					/* If it is, get word / item counts from that idlink table. */
					
					/* we need the whereclause, so set it up if it has not run already .... */
					if (! $this->hasrun_initialise_idlink_where)
						$this->initialise_idlink_where();
					
					list($field) = $seen_idlinks; /* safe cos there is only one */
					$idlink_s_att_handle = $this->item_type. '_' . $field;
					$table = get_idlink_table_name($this->corpus, $idlink_s_att_handle);
					
					$sql = "select sum(n_items), sum(n_tokens) from `$table` where {$this->stored_idlink_where[$idlink_s_att_handle]}";

					list($this->n_items, $this->n_tokens) = mysql_fetch_row(do_mysql_query($sql));
					
					return;
				}
				
				/* if (1 != count($seen_idlinks), then we have idlink criteria based on more than one idlink
				 * attribute (albeit on the same element). We can't work out the size from the idlink_xml table in this case,
				 * because we don't know how the ids on different idlink attributes intersect. 
				 * 
				 * So do nothing, and we will go through to the falback case. 
				 * 
				 * (And remember that having multiple idlink attributes on one element would be a pretty bizarre 
				 * setup anyway. So we should only very rarely be on this path.) 
				 */
			}
		}
		/* end if (all conditions are on just one XML element) */
		
		/* 
		 * So, we get to here if (1) the "only one element" test wasn't met; or (2) we exited
		 * the if-else tree above without returning. 
		 * 
		 * That means, if we're here, we have onditions on more than one XML element, maybe including text metadata;
		 * or a mix of idlink and classification on a single elememnt.
		 */
 

		/* FALLBACK CASE -- for all other situations, we have to use the cpos collection. */
		
		if (!$this->hasrun_initialise_cpos_collection)
			$this->initialise_cpos_collection();
		
		$this->n_items = count($this->cpos_collection);
		
		$this->n_tokens = 0;
		foreach($this->cpos_collection as $pair)
			$this->n_tokens += $pair[1] - $pair[0] + 1;

		/* end of size-of-Restriction initialisation! */		
		$this->hasrun_initialise_size = true;
	}
	
	
	
	
	private function initialise_cpos_collection()
	{
		/* an important note:
		 * ==================
		 * 
		 * For Restrictions which encompass many ranges (small or large), the cpos_collection can be a TOTAL ARSE
		 * of a drain on RAM, and out-of-memory errors may occur. The default PHP limit will almost certainly need to be lifted
		 * (i.e. "memory_limit" directive in php.ini).
		 * 
		 * Longterm TODO: think - would the cpos_collection be better cached to disk instead of RAM????
		 * (Currently we DO cache to MySQL, but only once we're done with ther Restriction. The whole thing still has to be
		 * in RAM while we're using it. This uses up many many megabytes of RAM even with relatively small corpora -
		 * note, for instance, that to handle utterance boundaries in a 5 MW c orpus I had to increase PHP's RAM limit
		 * from the default 128 MB to 512 MB.  
		 */
		
		global $cqp;
		
		foreach($this->parsed_conditions as $att => $cond_array)
		{
			$new_collection = array();
			
			/* if --text is the only one, then we don't need the cpos collection. So, this func should not be called unless
			 * there is something other than --text in the parsed conditons (be that *as well as* or *instead of*.
			 */
			if ('--text' == $att)
			{
				/* text meta is easy ... */
				
				if (!$this->hasrun_initialise_text_metadata_where)
					$this->initialise_text_metadata_where();
				$sql = "select cqp_begin, cqp_end from text_metadata_for_{$this->corpus} WHERE {$this->stored_text_metadata_where} order by cqp_begin asc";
				$result = do_mysql_query($sql);
				while (false !== ($r = mysql_fetch_row($result)))
					$new_collection[] = $r;
			}
			else if ('~~within' == $cond_array)
			{
				/*  we just read off the entire s-attribute. */
				
				$source = get_s_decode_stream($att, false, $this->corpus);
				while (false !== ($line = fgets($source)))
				{
					$t = explode("\t", trim($line));
					$new_collection[] = array((int)$t[0], (int)$t[1]);
				}
				pclose($source);
			}
			else
			{
				/* we have an array of 1+ value-conditions for an xml-family $att. 
				 * 
				 * Use CQP/tabulate to extract the data, and apply the value-tests in PHP code.
				 * (why? because, although CQP could apply tests on s-attribute values
				 * as a global condition, it could not test date ranges.)
				 */
				$this->affirm_cqp_connected_from_our_corpus();

				$possible_matches = array();
				$idlinks_to_interrogate = array();
				
				foreach ($cond_array as $c)
				{
					list($field, $value) = explode('~', $c);

					if (false !== strpos($field, '/'))
					{
						list($idlink_att, $idlink_field) = explode('/', $field);
						if (!isset($possible_matches[$idlink_att]))
							$possible_matches[$idlink_att] = array();
						$idlinks_to_interrogate[] = $idlink_att;
						/* we collect only a list of which idlinks to interrogate. we don't actually populate 
						 * possible matches till below - which we do using the SQL table.       */
					}
					else
					{
						/* this is a classification field, so add supplied category to list of possible values. */
						if (!isset($possible_matches[$field]))
							$possible_matches[$field] = array();
						$possible_matches[$field][] = $value;
					}
				}
				
				/* at this point: check the compiled IDLINK conditions, add to possible matches */
				if (!empty($idlinks_to_interrogate))
					if (!$this->hasrun_initialise_idlink_where)
						$this->initialise_idlink_where();
				foreach(array_unique($idlinks_to_interrogate) as $idlink)
				{
					$possible_matches[$idlink] = array();
					$id_att = "{$att}_{$idlink}";
					$table = get_idlink_table_name($this->corpus, $id_att);
					$sql = "select distinct `__ID` from `$table` where {$this->stored_idlink_where[$id_att]}";
					$result = do_mysql_query($sql);
					while (false !== ($r = mysql_fetch_row($result)))
						list($possible_matches[$idlink][]) = $r;
				}
				
				/* we are working on s-atts belonging to the same family, so we know that they all have matching position numbers;
				 * rather than multiple pipes to cwb-s-decode, let's use CQP to get the different attributes one by one. */
				$cqp->execute("RestrScan = <$att>[] expand to $att");

				/* set up the vars we need to run, then interpret, the query tabulation command. */
				$fieldstr = '';
				$mapper = array();
				$i = 0;
				foreach ($possible_matches as $field => $v)
				{
					if ('' != $fieldstr)
						$fieldstr .= ' , ';
					$fieldstr .=" match {$att}_$field";
					$mapper[$field] = $i++;
				}
				
				/* tabulate in batches to save RAM */
				for ($size = $cqp->querysize('RestrScan'), $batch_start = 0 ; $batch_start < $size ; $batch_start += 1000)
				{
					$batch_end = $batch_start + 999;
					if ($batch_end >= $size)
						$batch_end = $size - 1; 
					
					$tab_cmd = "tabulate RestrScan $batch_start $batch_end $fieldstr, match, matchend";
					
					$lines = $cqp->execute($tab_cmd);
					
					foreach ($lines as $l)
					{
						$arr   = explode("\t", trim($l));
						$end   = (int)array_pop($arr);
						$begin = (int)array_pop($arr);
						
						$add = true;
						
						foreach ($possible_matches as $field => $arr_of_vals)
						{
							if (! in_array($arr[$mapper[$field]], $arr_of_vals))
							{
								$add = false;
								break;
								//TODO
								//TODO
								//TODO
								//TODO the test above will need more complex when we have DATE!
								//TODO
								//TODO
								//TODO
							}
						}
						
						if ($add)
							$new_collection[] = array($begin, $end);
					}
				}
				
				/* and finally note: the fact we have entered this branch of the code makes the content
				 * of this restriction NECESSARY TO BE CACHED!! for reasons that should be obvious. */
				$this->needs_to_be_added_to_cache = true;
				/* but see also below -- this will also be set to true if there are conditions on multiple atts. */
				
			} /* phew! end of the code for s-attribute xml-w-values checking. */ 
			
			/* outside the if-else again: whichever bit of the ladder executed, we now have a new collection. */
			$this->intersect_cpos_collection($new_collection);
			
			/* and now we loop to the next condition set. */
		}
		/* end of foreach -- just a wee bit of admin left.... */
		
		/* if the above loop went round more than once, ALSO cache the restriction - as the combination even of easy ones
		 * is hard-work to assemble and worth caching for performance reasons. */
		if (2 <= count($this->parsed_conditions))
			$this->needs_to_be_added_to_cache = true;

		$this->hasrun_initialise_cpos_collection = true;
	}
	
	
	
	/**
	 * Takes an array of cpos pairs, and combines it with the existing $this->cpos_collection to create a new
	 * $this->cpos_collection containing only the ranges of cpos that are within the content of both the existing
	 * collection and the new one. 
	 * 
	 * NB. It may turn out that for performance reasohns, we ned equiv funcs that instead of reading from an array with next(),
	 * instead read [$a, $b] from a pipe or from a MySQL result. We'll worry about two parallel functions for that
	 * later (cross that bridge when we come to it). 
	 * 
	 * @param array $collection   An array of arrays where each inner array is a pair of ints representing cpos.
	 */
	private function intersect_cpos_collection($collection)
	{
		/* if the existing array is NULL (its init value), this is the first array to be intersected: take argument array as-is. 
		 * if the argument is the empty array, then there are NO areas of the corpus that are in both cpos collections. */
		if (is_null($this->cpos_collection) || empty($collection))
		{
			$this->cpos_collection = $collection;
			return;
		}
		
		/* pre loop initialise... */
		
		$new_collection = array();
				
		$ix = 0; /* index into current collection */
		
		reset($collection); /* just in case */
		
		list($a, $b) = current($collection); /* no return test as we know the input array has at least one element. */
		
		/* nb: $a      = start of range from incoming colleciton. $b      = end of that range.
		 *     $this_a = start of range in  existing collection.  $this_b = end of that range.
		 */
		
		/* there's a lot of repetition in the if-else tree within this loop, but it serves to make the algorithm clearer. */
		while (true)
		{
			/* if the existing array has run out, break */
			if (!isset($this->cpos_collection[$ix]))
				break;
			list($this_a, $this_b) = $this->cpos_collection[$ix]; /* this is reinitialised after every "continue" */
			
			/* ALGORITHM: check the start point $a against $this_a and $this_b. Then, if necessary, check the end point $b. */
			
			if ($a > $this_b)
			{
				/* Range $a,$b is after $ix. So we skip $ix: it is not part of the intersection. Retain $a,$b for next loop. */
				++$ix;
				continue;
			}
			else if ($a >= $this_a) /*....  also, inevitably, ( $a <= $this_b ) due to fail of pervious check. */
			{
				/* $a is between $this_a and $this_b, I.E. lies within the pair pointed to by $ix */
				if ($b <= $this_b)
				{
					/* $b is also within that range: so the entirety of $a,$b is within $ix. Write $a,$b and move to the next $a,$b;
					 * but keep $ix, because it's possible the next $a,$b will also be within (or begin within) that range. */
					$new_collection[] = array($a, $b);
					$t = next($collection);
					if (false === $t)
						break;
					list($a, $b) = $t;
					continue;
				}
				else
				{
					/* $a is within range, but $b isn't. So write the range $a,$this_b, and move to the next $ix;
					 * but keep $a,$b because it's possible that it (its latter part!) will intewrsect with the next $ix. */
					$new_collection[] = array($a, $this_b);
					++$ix;
					continue;
				}
			}
			else /* I. E.  else if ($a < $this_a )  -- logically unavoidable. */
			{
				/* $a is before $this_a */
				if ($b < $this_a)
				{
					/* the whole range $a,$b is before the $ix range, and does not intersect it. So discard $a,$b and move to the next. */
					$t = next($collection);
					if (false === $t)
						break;
					list($a, $b) = $t;
					continue;
				}
				else if ($b > $this_b)
				{
					/* the $ix range is entirely within the range $a,$b. So, write the $ix range, then loop to next $ix range. */
					$new_collection[] = $this->cpos_collection[$ix];
					++$ix;
					continue;
				}
				else /* I. E.  else if ($b >= $this_a && $b <= $this_b)  -- logically unavoidable. */
				{
					/* $b is within the range, but $a isn't. So, write the range from the beginning of $ix to $b; then loop */
					$new_collection[] = array($this_a, $b);
					$t = next($collection);
					if (false === $t)
						break;
					list($a, $b) = $t;
					continue;
				}
			}
		}
		
		$this->cpos_collection = $new_collection;
		/* note that adjacent ranges are NOT merged here, but they will be on write-to-dumpfile.... */
		
		/*
		 * Stefan suggests:
		 * ----------------
		 * "The algorithm could also take a different approach which might make it easier to convince ourselves that it is correct 
		 * (and whether there might be adjacent ranges or not).  
		 * 
		 * The idea here is to scan the corpus for (maximal) cpos ranges in the intersection, looking alternately for the start 
		 * cpos and end cpos of such a range.  
		 * 
		 * Let $A refer to the next/current range in the internal collection and $B to the next/current range in the argument $collection:
		 * 
 		 * 1. scan starting from current position $cpos; increment $A and/or $B as long as $cpos is after end of either
 		 * 2. next start candidate $start is the _larger_ of $A[0] and $B[0]
 		 * 3. validate that $start is in both ranges;  otherwise go back to 1.
		 * 4. $start is the beginning of an intersection range; its $end is the smaller of $A[1] and $B[1]; append ($start, $end) to $new_collection
		 * 5. set $cpos to $end+1, and go back to 1."
		 * 
		 * this is something we should consider later.^H^H^H^H^H^H^H^H^H^H^H^H^H
		 * <SE adds: No need to – your algorithm is tidy and clear now, and I don't think my alternative would be more efficient.>
		 */
	}

	
	
	
	private function set_corpus($c)
	{
		if (empty($c))
		{
			global $Corpus;
			$this->corpus = $Corpus->name;
		}
		else
			$this->corpus = $c;
	}
	
	
	
	
	/* used in just one place but it's a horrid hunk of code, so tuck it away here. */ 
	private function affirm_cqp_connected_from_our_corpus()
	{
		global $Corpus;
		global $cqp;
	
		if (! isset($cqp)) /* probably not needed: most likely $cqp *will* be set. */
		{
			if ($this->corpus == $Corpus->name)
				connect_global_cqp($Corpus->cqp_name);
			else
			{
				$c_info = get_corpus_info($this->corpus);
				connect_global_cqp($c_info->cqp_name);
			}
		}
	}
	
	
	/*
	 * =====================================
	 * RESTRICTION CACHE INTERACTION METHODS
	 * =====================================
	 * 
	 * Setting up a complex Restriction is so complex and [disk / ram / maybe even cpu] intensive, 
	 * we use a database cache to store the results of setup (mapping the serialisation to a longblob
	 * containing the binary data of the cpos_collection.
	 * 
	 * A global configuration variable controls the max size of this cache; entries can be touched.
	 * This means that, once set up, a difficult Restriction should hang around in cache for the
	 * duration of a user session (if there are such a large number of users as to prevent this, then
	 * you need to up the cache limit!) and the user only experiences the delay of setup *once*.
	 * 
	 * This is particularly important when it comes to last-restrictions: they have to be pulled back
	 * into memory.  
	 * 
	 * The restriction cache control functions themselves are outside of the Restriction object;
	 * the two methods here just control the interaction of a given Restriction object with those
	 * functions. 
	 * 
	 * Caching is only allowed for the types of restriction that have the performance characteristics
	 * we have noted as problematic. It is not allowed, for example, for those that are based purely
	 * on text metadata and thus can easily be whipped out from the xml table.
	 */
	
	
	/** Gets this restriction's setup data from cache, if possible, instantly setting the cpos_collection and size variables. */
	private function retrieve_from_cache()
	{
		$record = check_restriction_cache($this->corpus, $this->serialised);
		
		if (false === $record)
			return false;
		
		$this->n_items = $record->n_items;
		$this->n_tokens = $record->n_tokens;
		$this->hasrun_initialise_size = true; 
		/* the function has NOT run, but the results of it running in a previous instance have been retrieved. */
		
		$this->cpos_collection = untranslate_restriction_cpos_from_db($record->data);
		$this->hasrun_initialise_cpos_collection = true;
		/* ditto! */

		/* critically note that this data has been set up without "needs to be added to cache" being set to true.
		 * So we won't end up inserting a second copy of this object's data to cache. */
		
		touch_restriction_cache($record->id);
		
		return true;
	}

	/** Puts this restriction's setup data into cache, if doing so has been flagged as necessary at some point. */
	private function commit_to_cache()
	{
		if (!$this->needs_to_be_added_to_cache)
			return false;
		
		add_restriction_data_to_cache($this->corpus, $this->serialised, $this->n_items, $this->n_tokens, $this->cpos_collection);
		
		delete_restriction_overflow();
		
		return true;
	}
	
	/** Adds this Restriction to the Subcorpus cache as "last restrictions". */
	public function commit_last_restriction()
	{
		global $User;
		/* note that normally, only the Subcorpus object manipulates the saved_subcropora table. 
		 * We make an exception here because of the known special nature of --last_restrictions. */
		
		/* the escape is **probably** not needed.... */
		$s = mysql_real_escape_string($this->serialise());
		
		$result = do_mysql_query("select id from saved_subcorpora 
									where corpus = '{$this->corpus}' and user = '{$User->username}' and name = '--last_restrictions'");
		if ( 0 == mysql_num_rows($result) )
		{
			do_mysql_query("insert into saved_subcorpora 
								(name,                  corpus,            user,                content, n_items,        n_tokens)
								values
								('--last_restrictions', '{$this->corpus}', '{$User->username}', '$s',    $this->n_items, $this->n_tokens)");
		}
		else
		{
			list($id) = mysql_fetch_row($result);
			do_mysql_query("update saved_subcorpora set content = '$s', n_items = {$this->n_items}, n_tokens = {$this->n_tokens} where id = $id");
		}
	}
	
	/*
	 * ==============
	 * ACCESS METHODS
	 * ==============
	 */
	
	
	
	/**
	 * Gets a string that can go to the database.
	 */
	public function serialise()
	{
		return $this->serialised;
	}
	
	
	/**
	 * Gets an alternative encoding for the Restriction that can be put into a link.
	 * (There will be no ampersand at the beginning or end of the string.)
	 * 
	 * This is, in effect, the "undo" function for new_from_url. 
	 */
	public function url_serialise()
	{
		/* quick short circuit.... */
		if (empty($this->serialised))
			return 'del=begin&del=end';
		
		/*
		 * We map from internal data structure format into a URL string.
		 */
		$urlbit = 'del=begin';
		
		foreach($this->parsed_conditions as $att_family => $conditions)
		{
			if ($conditions == '~~~within')
				$urlbit .= "&t=$att_family";
			else
				foreach ($conditions as $c)
					$urlbit .= "&t=$att_family|" . urlencode($c);
			/* $c must be urlencoded because dates can contain + */
		}
		
		$urlbit .= '&del=end';
		
		return  $urlbit;
	}

	
	/**
	 * Integer values of size in tokens.
	 */
	public function size_tokens()
	{
		return $this->n_tokens;
	}
	
	
	/**
	 * Integer value of size in items. You can get the type of item as an out-parameter.
	 */
	public function size_items(&$item = NULL)
	{
		$item = $this->item_type;
		return $this->n_items;
	}
	
	
	
	/**
	 * Returns the size of the intersection between this Restriction and a specified set of reductions.
	 * 
	 * See notes on QueryScope. If this Restriction is not a whole set of texts, return false.
	 * 
	 * Otherwise return an array: 0=>size in tokens, 0>size in texts.
	 */
	public function size_of_classification_intersect($category_filters)
	{
		if ($this->item_type != 'text')
			return false;
		
		if (!$this->hasrun_initialise_text_metadata_where)
			$this->initialise_text_metadata_where();
		
		foreach($category_filters as &$e)
			$e = preg_replace('/(\w+)~(\w+)/', '(`$1` = \'$2\')', $e);
		$where = implode(" && ", $category_filters) . ' && ' . $this->stored_text_metadata_where; 
		
		return mysql_fetch_row(do_mysql_query("select sum(words), count(*) from text_metadata_for_{$this->corpus} where $where")) ; 
	}

	
	/**
	 * Size in items as printable string.
	 */
	public function print_size_items()
	{
		if ($this->item_type == '@')
			return number_format((float)$this->n_items) . ' corpus segment' . ($this->n_items > 1 ? 's' : '');
		else if ($this->item_type == 'text')
			return number_format((float)$this->n_items) . ' text' . ($this->n_items > 1 ? 's' : '');
		else
		{
			$info = get_xml_info($this->corpus, $this->item_type); 
			return number_format((float)$this->n_items) . ' ' . escape_html($info->description) . ' unit' . ($this->n_items > 1 ? 's' : '');
		}
	}
	
	public function print_size_tokens()
	{
		return number_format((float)$this->n_tokens);
	}
	
	
	
	
	/**
	 * Gets an array of item IDs. 
	 * 
	 * If the Restriction is a set of whole texts, text_ids are returned (and the out params set to 'text' and 'id'
	 * respectively). 
	 * 
	 * If the Restriction is a set of whole elements of some other XML element, then the first out param is set to that.
	 * The second out param is set to "", and false is returned. 
	 * (The expectation is that more complex methods will be needed to get an item list in this case: we need information relating to unique
	 * IDs / IDLINKS and a collection of IDs there / a sequence position list / a set of arbitrary cpos pairs.)
	 * 
	 * [TODO TODO TODO. Monitor use of this func VERY CAREFULLY.]
	 * 
	 * Otherwise returns false (e.g. conditions on multiple elements), assigns the special string @ to the 1st param, and "" to the second.
	 * 
	 * @param string $item_type        Out parameter: if present, set to the item type (even when false is returned).
	 * @param string $item_identifier  Out parameter: if present, set to the item identifier or to "".
	 * @return array                   Sorted list (or, false). See description.
	 */
	public function get_item_list(&$item_type = NULL, &$item_identifier = NULL)
	{
		/* Notes on use of this  method before the rewrite: -- it was 2 places.
		 * 
					freqtable-cwb.inc.php:369:		$text_list = Restriction::new_by_unserialise($restriction)->get_item_list($itemcheck);
					 ---> gets a text list for freqlist creation 
					      (function now replaced amnd moved anyway.... see freqtable.inc)
					 ---> OK, becasue this code is from the cwb-freq index: does not apply, cos we can't use the freq index unless it's text.

					subcorpus-admin.inc.php:144:		$list_of_texts_to_show_in_form = implode(' ', $restriction->get_item_list());
					 ---> gets an item list for subcorpus modifiying. Not needed yet......
					 
					 And now also used in this file by the Subcorpus object's get_item_list method.
		 */
		
		if ($this->item_type == 'text')
		{
			/* consists of a whole number of complete texts (selected by metadata) */
			$item_type = 'text'; 
			$item_identifier = 'id';

			if (!$this->hasrun_initialise_text_metadata_where)
				$this->initialise_text_metadata_where();
			
			$result = do_mysql_query("select text_id from text_metadata_for_{$this->corpus} 
											where {$this->stored_text_metadata_where} order by text_id asc");
			
			$list = array();
			while ($r = mysql_fetch_row($result))
				$list[] = $r[0];
			return $list;
		}
		else if ($this->item_type == '@')
		{
			/* consists of an arbitrary set of cpos ranges. */
			$item_type = '@';
			$item_identifier = '';
			return false;
		}
		else
		{
			/* consists of a whole number of ranges of some s-attribute */
			$item_type = $this->item_type; // for now, we jsut report what element is invovled. 
			$item_identifier = '';
			return false;
			// TODO see note from above:
		    // TODO: what do we want in this case? list of articles? list of utterances? list of cpos pairs? 
		    // An idlink if there is one? a uniquid if there is one?
		}
	}
	
	public function get_corpus()
	{
		return $this->corpus;
	}
	
	
	
	/*
	 * frequency table functions
	 * 
	 * HAVE NOT BEEN THOUGHT THROUGH AT ALL. 
	 * 
	 * Largely a copy of the Subcorpus funcitons, then QueryScope can delegate. The setup func is different. 
	 * 
	 */
	/**
	 * Checks whether a frequency table for the part of the corpus represented by the Restriction exists in cache.
	 * 
	 * @return bool  True iff the Restriction has a frequency table.
	 */	
	public function has_freqtable()
	{
		/* no need to refresh, so only call setup if this is the first time. */
		if (is_null($this->freqtable_record))
			$this->setup_freqtable_record();
		return (false !== $this->freqtable_record);
	}
	public function get_freqtable_record()
	{
		if ($this->has_freqtable())
			return $this->freqtable_record;
		else
			return false;
	}
	public function get_freqtable_base()
	{
		if (is_null($this->freqtable_record))
			$this->setup_freqtable_record();
		return $this->freqtable_record->freqtable_name;
	}
	private function setup_freqtable_record()
	{
		/* (A) look for a freq table for this Restriction */
		$sql = "select * from saved_freqtables where corpus = '{$this->corpus}' and query_scope = '{$this->serialised}'";
		$result = do_mysql_query($sql);
		
		if (0 < mysql_num_rows($result))
		{
			$this->freqtable_record = mysql_fetch_object($result);
			return true;
		}
		
		/* (B) look for a freq table for a Subcorpus whose content matches this Restriction, i.e. whose freq list was based on these restrictions */
		$sql = "select * from saved_subcorpora 
			where corpus = '{$this->corpus}' 
			and content = '{$this->serialised}' 
			and name !='--last_restrictions'"; /* because we know that Last Restrictions don't have freq lists. */
		$result = do_mysql_query($sql);
		
		while (false !== ($sc = Subcorpus::new_from_db_result($result)))
		{
			if ($sc->has_freqtable())
			{
				$this->freqtable_record = $sc->get_freqtable_record();
				return true;
			}
		}
		$this->freqtable_record = false;
		return true;
	}
	
	
	
	/**
	 * Given the value field of an HTML input of type "t", 
	 * returns true if that should be checked for the form to match this parameter
	 * otherwisde false.
	 * 
	 * EXAMPLE: $r->form_t_value_is_activated('-|classX~catY')
	 * 
	 * Returns true if the "class X should be cat Y for text metadata" box should be checked.
	 */
	public function form_t_value_is_activated($t_value)
	{			
		if (false === strpos($t_value, '|'))
			/* do we have this as a must-appear-wiothin? e. */
			return $this->parsed_conditions[$t_value] = '~~~within';

		list($field, $cond) = explode('|', $t_value);
		if ($field == '-')
			$field = '--text';
		if (isset($this->parsed_conditions[$field]))
			return in_array($cond, $this->parsed_conditions[$field]);
		else
			return false;
	}
	
	
	
	/** Activate this Restriction AS a "subcorpus" (sensu CQP) within the global CQP child process. */
	public function insert_to_cqp()
	{
		global $Config;
		global $cqp;
		
		$dumpfile = "{$Config->dir->cache}/sc_temp_{$Config->instance_name}"; 
		$this->create_dumpfile($dumpfile);

		$cqp->execute("undump Curr-Restriction < '$dumpfile'");
		$cqp->execute("Curr-Restriction");
		
		unlink($dumpfile);
	}
	
	
	/**
	 * Creates a CQP dumpfile representing this Restriction at the specified location.
	 * 
	 * Returns the number of rows in the dumpfile.
	 * 
	 * @param string $path
	 */
	public function create_dumpfile($path)
	{
		/* disk stream ready, buffer var ready */
		$dest = fopen($path, 'w');
		$buf = NULL;
		/* the use of a buffer object allows us to merge adjacent ranges. */ 

		if ($this->item_type == 'text')
		{
			/* rely on the SQL of the metadata table */
			if (! $this->hasrun_initialise_text_metadata_where)
				$this->initialise_text_metadata_where();

			$sql = "SELECT cqp_begin, cqp_end FROM text_metadata_for_{$this->corpus} WHERE {$this->stored_text_metadata_where} ORDER BY cqp_begin ASC";
			$result = do_mysql_query($sql);
			
			/* stream to disk w/ buffering for merger of adjacent pairs. */
			while (false !== ($r = mysql_fetch_object($result)))
			{
				if (empty($buf))
					$buf = $r;
				else
				{
					if ($buf->cqp_end >= ($r->cqp_begin-1))
						$buf->cqp_end = $r->cqp_end;
					else
					{
						fputs($dest, "{$buf->cqp_begin}\t{$buf->cqp_end}" . PHP_EOL);
						$buf = $r;
					}
				}
			}				
			/* and write the contents of the buffer at loop end */
			if (!empty($buf))
				fputs($dest, "{$buf->cqp_begin}\t{$buf->cqp_end}" . PHP_EOL);
		}
		else
		{
			if (!$this->hasrun_initialise_cpos_collection)
				$this->initialise_cpos_collection();
			
			/* stream to disk w/ buffering for merger of adjacent pairs. */
			reset($this->cpos_collection);
			for ($r = current($this->cpos_collection) ; is_array($r) ; $r = next($this->cpos_collection))
			{
				if (empty($buf))
					$buf = $r;
				else
				{
					if ($buf[1] >= ($r[0]-1))
						$buf[1] = $r[1];
					else
					{
						fputs($dest, "{$buf[0]}\t{$buf[1]}" . PHP_EOL);
						$buf = $r;
					}
				}
			}
			/* and write the contents of the buffer at loop end */
			if (!empty($buf))
				fputs($dest, "{$buf[0]}\t{$buf[1]}" . PHP_EOL);
		}
		
		fclose($dest);
	}

	
	
	
	/*
	 * ================
	 * PRINTOUT METHODS
	 * ================
	 */
	
	
	/**
	 * Returns a printable description-string for the restriction, for use in the interface.
	 * 
	 * @param bool $html  Whether to produce an HTML string. (Default: yes.) Pass false for plaintext.
	 */
	public function print_as_prose($html = true)
	{
		// longterm-TODO this func does not support DATE datatype yet. 
		
		if ($html)
		{
			$em = '<em>';
			$slashem = '</em>';
			$lq = '&ldquo;';
			$rq = '&rdquo;';
		}
		else
		{
			$em = $slashem = '*';
			$lq = '"';
			$rq = '"';
		}
		
		/* only bother setting up the info array if we know at least 1 non-text-based condition is in play */
		if ($this->item_type != 'text')
		{
			$xmlinfo = get_xml_all_info($this->corpus);
			foreach ($xmlinfo as $k=>$v)
				$xmlinfo[$k]->catdescs = xml_category_listdescs($this->corpus, $k);
		}
		
		/* likewise for idlink info: only set it up if there is at least 1 idlink type condition;
		 * to avoid a second idenitcal loop, this is done in the loop below (when actually used. */
		$idlinkinfo = array();
		
		
		$prose_array = array();
		
		foreach ($this->parsed_conditions as $att_fam => $conds)
		{
			if ('--text' == $att_fam)
			{
				$working = 'texts meeting criteria ';
				$w_arr = array();
				foreach($conds as $c)
				{
					list($field, $match) = explode('~', $c);
					if (false !== strpos($field, '/'))
					{
						/// TODO deal with idlink.
					}
					else
					{
						$expl = metadata_expand_attribute($field, $match, $this->corpus);
						if (empty($w_arr[$field]))
							$w_arr[$field] = $em . $expl['field'] . $slashem . ': ' ;
						else 
							$w_arr[$field] .= ' or ';
						$w_arr[$field] .= $em . $expl['value'] . $slashem;
					}
				}
				$working .= $lq . implode('; ', $w_arr) . $rq;  
			}
			else if ('~~~within' == $conds)
			{
				$working = 'occuring within a ' . escape_html($xmlinfo[$att_fam]->description) . ' region'; 
			}
			else
			{
				$working = $em . escape_html($xmlinfo[$att_fam]->description) . $slashem . ' regions with ';
				$w_arr = array();
				foreach($conds as $c)
				{
					list($field, $match) = explode('~', $c);
					if (false !== strpos($field, '/'))
					{
						/* we need to deal with idlink ... */
						list($idlink_att, $idlink_field) = explode('/', $field);
						if (empty($idlinkinfo[$idlink_att]))
						{
							/* idlinkinfo : set up on need */
							$idlinkinfo[$idlink_att] = get_all_idlink_info($this->corpus, "{$att_fam}_$idlink_att");
							foreach ($idlinkinfo[$idlink_att] as $k=>$v)
								$idlinkinfo[$idlink_att][$k]->catdescs = idlink_category_listdescs($this->corpus, "{$att_fam}_$idlink_att", $k);
						}
						if (empty($w_arr[$field]))
							$w_arr[$field] 
								= $em . escape_html($xmlinfo["{$att_fam}_$idlink_att"]->description . ':' 
									. $idlinkinfo[$idlink_att][$idlink_field]->description) . $slashem . ' is ' 
										;
						else
							$w_arr[$field] .= ' or ';
						$w_arr[$field] .= $em . escape_html($idlinkinfo[$idlink_att][$idlink_field]->catdescs[$match]) . $slashem;
						
					}
					else
					{
						if (empty($w_arr[$field]))
							$w_arr[$field] = $em . escape_html($xmlinfo["{$att_fam}_$field"]->description) . $slashem . ': ' ;
						else 
							$w_arr[$field] .= ' or ';
						$w_arr[$field] .= $em . escape_html($xmlinfo["{$att_fam}_$field"]->catdescs[$match]) . $slashem;
					}
				}
				$working .= $lq . implode('; ', $w_arr) . $rq;  
			}
			
			/* in whichever case ... */
			$prose_array[] = $working;
		}
	
		return implode(', and to ', $prose_array);
	}

}
/*
 * ======================== 
 * end of class Restriction
 * ========================
 */







/**
 * This class represents a subcorpus as stored in the database.
 *  
 * Generally, rather than interact directly with the database, other functions and scripts should interact with
 * this class instead. 
 * 
 * Comments within the class definition explain the internal database representation.
 */
class Subcorpus 
{
	/** knowledge about the database; stored as private static 'cos a const can't be an array. */
	private static $DATABASE_FIELD_NAMES = array(
		'id',
		'name',
		'corpus',
		'user',
		'restrictions',
		'text_list',
		'n_items',
		'n_tokens'
	);
	
	/* constants for the $mode variable */
	
	/** mode is set to this before we have populated/loaded the obj. */
	const MODE_NOT_POPULATED = 0;
	const MODE_LIST          = 1;
	const MODE_RESTRICTION   = 2;
	const MODE_ARBITRARY     = 3;
	
	/*
	 * ===============
	 * DATABASE FIELDS
	 * ===============
	 */
	
	/**	The integer ID of a saved subcorpus. If the object has not had a DB subcorpus loaded into it, then this will be NULL. */
	public $id = NULL;
	
	/** The name that the user gave to this subcorpus: 200 character handle string. */
	public $name;
	
	/** Name of the corpus that this subcorpus is part of. */
	public $corpus;
	
	/** Username of the owner of this subcorpus: handle string. */
	public $user;

	/** The subcorpus content: i.e. the encoded (serialised) representation of what this corpus has in it. */
	private $content;

	/** size of subcorpus in n of items (i.e. whateverr unit it is "denominated" in */
	private $n_items;
	
	/** size of subcorpus in n of tokens  */
	private $n_tokens;
	
	/* 
	 * variables that appear when the content is parsed
	 * ------------------------------------------------
	 * 
	 *  All relate to one-thing-or-anotehr in $this->content
	 * */
	
	/** set to one of the mode constants to indicate what kind of storage we have here. */
	private $mode;
	
	/* MODE_LIST variables */

	/** contains an array of items, when the subcorpus is MODE_LIST. Note: array not delimited string. */
	private $item_list;
	
	/** contains the xml element that the item list lists instances of, when the subcorpus is MODE_LIST. If it's text ID codes, = 'text'. */
	private $item_type;
	
	/** contains the attribute that contains the ID codes used in the list, when the subcorpus is MODE_LIST. If it's text ID codes, = 'id' */ 
	private $item_identifier;
	
	/* MODE_RESTRICTION variable */
	
	/** contains a Restriction object (set up on need not on auto!) when the subcorpus is MODE_RESTRICTION. */
	private $restriction = NULL;
	
	
	/*
	 * =================
	 * GENERAL VARIABLES
	 * =================
	 */

	/** Freqtable record (an stdClass from the database). Set to NULL when this has not been checked yet. Set to false if there is no table. */
	private $freqtable_record = NULL;
	
	/* * Parsed data: if the subcorpus's string representation indicates it is restriction based, put an object here on load.
	private $restriction;  currently variou8s emthods have local object where necessary.... */
	
	
	/*
	 * =============
	 * SETUP METHODS
	 * =============
	 */
	
	
	public function __construct()
	{
		/* do nothing */
	}
	
	/**
	 * Creates a blank subcorpus object, which can then be populated using one of the given methods, and then saved.
	 * 
	 * Typical usage (second step can use any of the populate methods):
	 * 
	 * $sc = Subcorpus::create($n, $c, $u);
	 * 
	 * $sc->populate_from_list('text', 'id', ['A','B']);
	 * 
	 * $sc->save();
	 * 
	 * By default, any existing corpus with the same name/user/corpus tuple will be deleted upon save (but not before).
	 */
	public static function create($name, $corpus, $user)
	{
		$obj = new self();
		
		$obj->name   = cqpweb_handle_enforce($name,   200);
		$obj->corpus = cqpweb_handle_enforce($corpus, 20);
		$obj->user   = cqpweb_handle_enforce($user,   64);
		/* note: for these three, plus at least one of text_list/restriction, to be available is the test for ready-to-save. */
		
		return $obj;
	}
	
// 	/**
// 	 * Creates and populates a special last-restrictions subcorpus.
// 	 * Separate func is needed because this is the only case where a non-handle savename is allowed.
// 	 *  
// 	 * @param Restriction $restriction
// 	 * @param string $corpus
// 	 * @param string $user
// 	 */
// 	public static function create_last_restrictions($restriction, $corpus, $user)
// 	{
// 		$obj = new self();
		
// 		$obj->name   = '--last_restrictions';
// 		$obj->corpus = cqpweb_handle_enforce($corpus, 20);
// 		$obj->user   = cqpweb_handle_enforce($user,   64);
		
// 		$obj->populate_from_restriction($restriction);
		
// 		return $obj;
// 	}
	
	
	
	/**
	 * Creates a new subcorpus object by cloning and then re-dubbing an existing Subcorpus. 
	 * 
	 * A new name is needed because you can't have two subcorpora in the same corpus and the same user
	 * that have the same name. 
	 * 
	 * The returned SC has been committed to the DB and is ready to roll. 
	 * 
	 * @param Subcorpus $source   The source of the duplication. 
	 * @param string    $newname  New subcorpus save-name (handle)
	 * @return Subcorpus          Record of the new subcorpus.     
	 */
	public static function duplicate($source, $newname)
	{
		$dupe = clone $source;
		
		/* so that on save it will create a new entry ...*/
		$dupe->id = NULL;
		$dupe->name = cqpweb_handle_enforce($newname, 200);
		
		/* if there exists a dumpfile (whether cached or data-storing), copy that too */
		$path = $source->generate_dumpfile_path();
		if (file_exists($path))
			copy($path, $dupe->generate_dumpfile_path());
		
		$dupe->save();
		
		return $dupe;
	}
	
	
	
	/**
	 * Saved subcorpora have a unique ID number.
	 * 
	 * This function returns a new object representing the saved subcorpus with the supplied ID
	 * (or false if no such subcorpus exists).
	 * 
	 * @param int $id    The ID to look up.
	 */
	public static function new_from_id($id)
	{
		$obj = new self();
		if (! $obj->load_from_id($id))
			return false;
		return $obj;
	}
	
	/**
	 * Creates a new Subcorpus object using variables from a MySQL result 
	 * (must be a "SELECT * from saved_subcorpora" query!)
	 * 
	 * If checking is enabled, and the result does not have the shape of a result selected from
	 * saved_subcorpora, CQPweb aborts.
	 *  
	 * @param resource $result  MySQL result containing the query record from ``saved_subcorpora''.
	 *                          Its internal pointer will be moved onwards-by-one.
	 * @param bool $check       Whether to check that the result has the correct fields.
	 * @return Subcorpus        New object(unless no object could be extracted from the result argument,
	 *                          in which case false).
	 */
	public static function new_from_db_result($result, $check = false)
	{
		$obj = new self();
		if (!$obj->load_from_db_result($result, $check))
			return false;
		return $obj;
	}
	
	/**
	 * Saved subcorpora are uniquely defined by the combination of (a) the name they were saved under,
	 * (b) the corpus in which they exist, (c) the user who "owns" them.
	 * 
	 * This function returns a new object representing the saved subcorpus that matches a supplied
	 * set of these three things (or false if no such subcorpus exists).
	 * 
	 * @param string $name    The save-name to look up.
	 * @param string $corpus  The corpus to look it up in.
	 * @param string $user    The username of the account whose subcorpora will be searched.
	 */
	public static function new_from_name($name, $corpus, $user)
	{
		$obj = new self();
		if (! $obj->load_from_name($name, $corpus, $user))
			return false;
		return $obj;
	}
	
	private function load_from_id($id)
	{
		$id = (int) $id;
		$result = do_mysql_query("select * from saved_subcorpora where id = $id");

		if (0 == mysql_num_rows($result))
			return false;
		
		return $this->load($result);
	}
	
	private function load_from_name($name, $corpus, $user)
	{
		$name   = mysql_real_escape_string($name);
		$corpus = mysql_real_escape_string($corpus);
		$user   = mysql_real_escape_string($user);
		
		$result = do_mysql_query("select * from saved_subcorpora where corpus='$corpus' and user='$user' and name='$name'");

		if (0 == mysql_num_rows($result))
			return false;
		
		return $this->load($result);
	}
	
	private function load_from_db_result($result, $check = false) 
	{
		if ($check)
		{
			/* We check that the result has the correct fields */
			$fields_present = array();
			for ( $i = 0, $n = mysql_num_fields($result)  ; $i < $n ; $i++ )
				$fields_present[] = mysql_field_name($result, $i);
						
			$diff = array_diff(self::$DATABASE_FIELD_NAMES, $fields_present);
			if (!empty($diff))
				exiterror("Subcorpus: cannot load a subcorpus from a DB record that lacks 1+ relevant fields!");
		}
		
		return $this->load($result);
	}

	
	/**
	 * Load variables from a known-good MySQL result. Backend for all new_from/load functions.
	 *  
	 * @param resource $result  MySQL result containing a "SELECT *" result from ``saved_subcorpora''.
	 *                          Its internal pointer will be moved onwards-by-one.
	 * @return                  True if the load was OK. False if something went wrong 
	 *                          (e.g. failed to read a result from $result). 
	 */
	private function load($result)
	{
		if (false === ($o = mysql_fetch_object($result)))
			return false;

		/* We assume the query has the correct fields, since it was either created by
		 * or checked by one of the outer functions that calls this inner function.
		 * 
		 * We ALSO assume that there is at least one result in the result set argument.
		 */

		$this->id = $o->id;
		
		$this->name   = $o->name;
		$this->corpus = $o->corpus;
		$this->user   = $o->user;
		
		$this->content  = $o->content;
		/*
		 * The following variables get parsed out from content :
		 * 
		 * mode
		 * item_type
		 * item_identifier
		 * item_list
		 * restriction  (object on demand).
		 * 
		 */
		$this->parse_content();
		
		$this->n_items  = $o->n_items;
		$this->n_tokens = $o->n_tokens;

		return true;
	}
	
	
	private function parse_content()
	{
		switch($this->content[0])
		{
		case '^':
			$this->mode = self::MODE_LIST;
			list(, $this->item_type, $this->item_identifier, $templist) = explode('^', $this->content);
			if ('' == $this->item_type)
			{
				$this->mode = self::MODE_ARBITRARY;
				break;
			}
			$this->item_list = explode(' ', $templist);
			break;
			
		case '@':
		case '$':
			$this->mode = self::MODE_RESTRICTION;
			$this->restriction = Restriction::new_by_unserialise($this->content);
			$this->restriction->size_items($this->item_type);  
			break;
		}
	}
	
	
	private function update_content()
	{
		switch($this->mode)
		{
		case self::MODE_ARBITRARY:
			$this->content = '^^^';
			break;
		case self::MODE_RESTRICTION:
			if (is_object($this->restriction))
				$this->content = $this->restriction->serialise();
			/* else: if there's no restriciton object, the restriction can only be 
			 * what is in $content, so $content must still be OK (that's only logic innit!) */
			break;
		case self::MODE_LIST:
			$this->content = "^{$this->item_type}^{$this->item_identifier}^" . implode(' ', $this->item_list); 
			break;
		}
	}
	
	
	/**
	 * Set up function: only run when we need to use the internal restriction object.
	 */
	private function assure_restriction()
	{
		if ($this->mode != self::MODE_RESTRICTION)
			return;
		if (is_null($this->restriction))
			$this->restriction = Restriction::new_by_unserialise($this->content, $this->corpus);
	}
	
	
	
	/*
	 * ================
	 * CREATION METHODS
	 * ================
	 * 
	 * These methods populate the database fields. 
	 * 
	 * Each sets ONE OF restrictions and text_list. (Which will be a single data field at some point in the future.)
	 * 
	 * Also, each sets n_tokens and n_items.
	 * 
	 * All return true or false if error as detected.
	 */

	/**
	 * Creates a new Subcorpus from a list of items.
	 * 
	 * Should only be called on a newly-created object. 
	 * 
	 * The item list is an array of Unique IDs. Item type = the XML family. 
	 * Item identifier = the sub-att with the IDs in it (if there is one).
	 * 
	 * If item identifier is an empty string, it is assumed that there is no ID, 
	 * and then the member IDs are integer positions on the main s-attribute (xml family).
	 * 
	 * "text" item type always implies "id" as the identifying attribute, and this is enforced.
	 * 
	 * NOTE: populate_from_arbitrary will be a DIFFERENT populate function. Don't use this one. . But, NB, TODO
	 * 
	 * If $check is true the list will be validated for handle status. By default it is false:
	 * we assume the caller has assembled the array in $item_list from a known-safe source.
	 */
	public function populate_from_list($item_type, $item_identifier, $item_list, $check = false)
	{
		$this->mode = self::MODE_LIST;
		$this->item_type = $item_type;
		$this->item_identifier = $item_identifier;
		
		$item_list = array_unique($item_list);
		
		sort($item_list, ('' == $this->item_identifier ? SORT_NUMERIC : SORT_STRING));
		
		if ($check)
		{
			$checkbools = array_map('cqpweb_handle_check', $item_list);
			if (in_array(false, $checkbools))
				exiterror("CQPwebSubcorpus: cannot populate a subcorpus from a list containing invalid item IDs.");
		}
		
		$this->item_list = $item_list;
		
		if ($this->item_type == 'text')
		{
			$this->item_identifier = 'id';
			
			$whereclause = translate_itemlist_to_where($item_list, true, 'text_id');
			
			$result = do_mysql_query("SELECT count(*), sum(words) FROM text_metadata_for_{$this->corpus} WHERE $whereclause");
			list($this->n_items, $this->n_tokens) = mysql_fetch_row($result);
		}
		else
		{
			/* n items is gettable as a count of the array */
			$this->n_items = count($this->item_list);
//TODO
//TODO
//TODO
//TODO there is a massive inefficiency here, namely that we will stream the whole attributre once (here)
// to find out how many tokens are in it, then again (in create_dumpfile)
// why not call create_dumpfile here, then scan the dumpfile ??
// OR EVEN BETTER: add argument to create_dumpfile ($update_tokens) to tell it to update the counts as it goes.
// for now the code is just repeated with minor variants, fret abnout this later, let's just get the damn thing working.
// END OF THE TODO.
//TODO
//TODO
//TODO
//TODO
			/* calculate n tokens for a subcorpus of xml units.... */
			$s_att_to_scan = $this->item_type . (empty($this->item_identifier) ? '' : '_' . $this->item_identifier);
			
			$source = open_xml_attribute_stream($this->corpus, $s_att_to_scan);
			
			$seqpos = 0;
			$this->n_tokens = 0;
			
			while (false !== ($l = fgets($source)))
			{
				$arr = explode("\t", trim($l));
				if ('' != $this->item_identifier)
				{
					/* it's an attribute with IDs: so check the 3rd value against the item list */
					if (in_array($arr[2], $this->item_list))
						$this->n_tokens += ($arr[1] - $arr[0]) + 1 ;
				}
				else
				{
					/* it's an attribute wihtout IDs: so check the seqpos againts the item list */
					if (in_array($seqpos, $this->item_list))
						$this->n_tokens += ($arr[1] - $arr[0]) + 1 ;
				}
				++$seqpos;
			}
			
			pclose($source);
		}

		/* generate the content field from the list. */
		$this->update_content();
		
		return true;
	}
	
	/**
	 * Should only be called on a newly-created object. 
	 * 
	 * @param string $qname  Qname identifier. If the query file isn't in cache, then the function will return false.
	 */
	public function populate_from_query_texts($qname)
	{
		global $cqp;

		/* check the connection to CQP */
		if (isset($cqp))
			$cqp_was_set = true;
		else
		{
			$cqp_was_set = false;
			connect_global_cqp();
		}

		/* get text list from query result */
		$grouplist = $cqp->execute("group $qname match text_id");
		if (!$cqp_was_set)
			disconnect_global_cqp();

		if (false === $grouplist)
			return false;
	
		$texts = array();
		foreach($grouplist as $g)
			list($texts[]) = explode("\t", $g);

		/* this then collapses to the procedure for text list... */
		return $this->populate_from_list('text', 'id', $texts);
	}
	
	/**
	 * Should only be called on a newly-created object.
	 * 
	 * Longterm-TODO: for now this ONLY works with texts, and will return false if you ask it
	 * to invert a Subcorpus that does not consist of complete texts.
	 * 
	 * Longterm-TODO: consider whether we should have inversion of other kinds of SC.
	 * 
	 * @param int $sc_id_to_invert  Integer ID of the subcorpus to invert.
	 */
	public function populate_from_inverting($sc_id_to_invert)
	{
		$source_sc = Subcorpus::new_from_id((int)$sc_id_to_invert);
		if ($source_sc->corpus != $this->corpus)
			exiterror("Subcorpus building error: you cannot move subcorpus data from one corpus to another!");
		
		$item_type = NULL;
		$item_identifier = NULL;
		$texts_to_exclude = $source_sc->get_item_list($item_type, $item_identifier);
		
		if ( $item_type != 'text' || $item_identifier != 'id' )
			return false;
		
		$result = do_mysql_query("select text_id from text_metadata_for_" . $this->corpus);
		
		$texts_for_new = array();
		
		while (false !== ($r = mysql_fetch_row($result)))
			if (!in_array($r[0], $texts_to_exclude))
				$texts_for_new[] = $r[0];
		
		/* so then it just collapses to... */
		return $this->populate_from_list($item_type, $item_identifier, $texts_for_new);
	}
	
	/**
	 * Should only be called on a newly-created object. 
	 * 
	 * We use an object rather than a serialised string as the argument,
	 * because we would need to create an object ANYWAY to get the sizes;
	 * and it's very likely that the caller had a Restriction anyway.
	 * 
	 * @param Restriction $restriction_object
	 */
	public function populate_from_restriction($restriction_object)
	{
		/* since Restrictions do not change after being created / loaded,
		 * it is OK for us to keep a reference to the argument object. */
		$this->restriction = $restriction_object;
		$this->mode        = self::MODE_RESTRICTION;
		$this->content     = $restriction_object->serialise(); /* so we do not need update content */
		$this->n_items     = $restriction_object->size_items();
		$this->n_tokens    = $restriction_object->size_tokens();
		
		return true;
	}
	
	
	
	/*
	 * ===============================
	 * GET-INFO AND PRINT INFO METHODS
	 * ===============================
	 */
	
	

	/**
	 * Returns an array containing the Ids (or sequence positions) of the items in this Subcorpus. 
	 * 
	 * The parameters are out-parameters: the first for the type of item this subcorpus contains,
	 * the second for the element containing its identifier. If the first is empty, then this SC is not
	 * a set of complete items. If the second is empty, then it is a set of complete items, BUT
	 * the items are specified by sequence position on the s-attribute, not by IDs, because we don't
	 * have a handy s-attribute of datatype UNIQUE_ID. 
	 * 
	 * If this is the kind of subcorpus that can't produce an item list, e.g. it contains arbitrary
	 * segments or a restriciton based on more than one kind of xml, it sets the out parameters to 
	 * empty strings, and returns false. 
	 * 
	 * If this is an unpopulated subcorpus, then it will return false, and will not set the out params.
	 */
	public function get_item_list(&$item = NULL, &$identifier = NULL)
	{
		switch($this->mode)
		{
		case self::MODE_NOT_POPULATED:
			return false;
			
		case self::MODE_ARBITRARY:
			$item = '';
			$identifier = '';
			return false;
			
		case self::MODE_RESTRICTION:
			/* the more-than-slightly tricky case! */
			$this->assure_restriction();

			// find out if this is an @ Restriction or a $ restriction. if it's an @ R, return false. 
			// if it's an $ then find out the unit and the identifier and get the list. 
			//TODO this will ahve to wait alas!!!!
			$check_item = $check_identifier = NULL;
			$list = $this->restriction->get_item_list($check_item, $check_identifier);
			if (false !== $list)
			{
				if ($check_item == 'text' && $check_identifier == 'id')
				{
					// right now , always true.
					$item = 'text';
					$identifier = 'id';
					
					return $list;
				}
				else 
					exiterror("CRITICAL LOGIC BUG BAD OUT-PARAMETERS FROM RESTRICTION GET ITEM LIST");
				// currently: not reached. 
				//TODO: fix the above
				//TODO
				//TODO
				//TODO
				
					
			}
			else
			{
				/* R::get_item_list *currently* returns false for any case other than a list of texts 
				 * to which we react by turning it into an arbitrary-style list. */
				$item = '';
				$identifier = '';
				return false;
			}
			/* not reached. */ 
			break;
			
		case self::MODE_LIST:
			$item = $this->item_type;
			$identifier = $this->item_identifier;
			return $this->item_list;
			
		}
		/* not reached: all switch paths lead to return. */
	}

	
	
	/**
	 * Integer values of size in tokens.
	 */
	public function size_tokens()
	{
		return $this->n_tokens;
	}
	
	
	/**
	 * Integer value of size in items. You can get the type of item as an out-parameter.
	 */
	public function size_items(&$item = NULL)
	{
		$item = $this->item_type;
		return $this->n_items;
	}
	
	
	
	/**
	 * Returns the size of the intersection between this Subcorpus and a specified set of reductions.
	 * 
	 * See notes on Subcorpus. If this Restriction is not a whole set of texts, return false.
	 * 
	 * Otherwise return an array: 0=>size in tokens, 0=>size in texts.
	 */
	public function size_of_classification_intersect($category_filters)
	{
		switch($this->mode)
		{
		case self::MODE_NOT_POPULATED:
		case self::MODE_ARBITRARY:
			return false;
		
		case self::MODE_RESTRICTION:
			//delegate to Restriction
			$this->assure_restriction();
			return $this->restriction->size_of_classification_intersect($category_filters);
			
		case self::MODE_LIST:
			if ($this->item_type != 'text')
				return false;
			
			foreach($category_filters as &$e)
				$e = preg_replace('/(\w+)~(\w+)/', '(`$1` = \'$2\')', $e);
			$filter_where_conditions = implode(" && ", $category_filters); 
			
			$text_list_where = translate_itemlist_to_where($this->item_list, true, 'text_id');
			
			return mysql_fetch_row(
					do_mysql_query(
							"select sum(words), count(*) from text_metadata_for_{$this->corpus} 
								where $filter_where_conditions and $text_list_where "
					)) ; 
		}
		return false;
	}
	
// TODO	
// TODO	
// TODO	
// TODO this is so sketchy I have no words for it.	
// TODO	
// TODO	
// TODO	
// TODO	
/**
 * horrible horrible function
 * @param unknown $restriction
 */
	public function get_intersect_with_restriction($restriction)
	{
		if ($this->mode != self::MODE_RESTRICTION)
			return false;
		if (
				preg_match('/^\$\^--text\|/', $set1 = $this->restriction->serialise())
				&& 
				preg_match('/^\$\^--text\|/', $set2 = $restriction->serialise())
			)
		{
			/* both based on text metadata : we can get an intersection. */
			$cats1 = explode('.', substr($set1, 8));
			$cats2 = explode('.', substr($set2, 8));
			$all = array_unique(array_merge($cats1, $cats2));
			sort($all);
			$newinput = '$^--text|' . implode('.', $all);
			return QueryScope::new_by_unserialise($newinput);
		}
		else 
			return false;
			
	}
	
	
	
	
	/**
	 * Gets printable string spelling out the size of the subcorpus in units of "items" (texts, etc.)
	 */
	public function print_size_items()
	{
		switch ($this->mode)
		{
		case self::MODE_ARBITRARY:
			return number_format((float)$this->n_items) . ' corpus segment' . ($this->n_items > 1 ? 's' : '');
			
		case self::MODE_LIST:
			if ($this->item_type == 'text')
				return number_format((float)$this->n_items) . ' text' . ($this->n_items > 1 ? 's' : '');
			else
			{
				$info = get_xml_info($this->corpus, $this->item_type); 
				return number_format((float)$this->n_items) . ' ' . escape_html($info->description) . ' unit' . ($this->n_items > 1 ? 's' : '');
			}
			break;
		
		case self::MODE_RESTRICTION:
			$this->assure_restriction();
			return $this->restriction->print_size_items();
		
		case self::MODE_NOT_POPULATED:
			return '??????????????????????????????????????????????????????????';
		}
	}
	
	
	public function print_size_tokens()
	{
		return number_format((float)$this->n_tokens);
	}
	
	
	/**
	 * Returns true if this subcorpus has a freqtable already there. Otherwise, false.
	 */
	public function has_freqtable()
	{
		/* no need to refresh, so only call setup if this is the first time. */
		if (is_null($this->freqtable_record))
			$this->setup_freqtable_record();
		return (false !== $this->freqtable_record);
	}
	
	
	/**
	 * Gets the base-section of the name of the MySQL tables cotnaining the frequincy list; false if they don't exist.
	 */
	public function get_freqtable_base()
	{
		if (is_null($this->freqtable_record))
			$this->setup_freqtable_record();
		return $this->freqtable_record->freqtable_name;
	}
	
	/**
	 * Temp func. May get rid of later. But, lotsa places currently expect an assoc array. 
	 */
	public function get_freqtable_record()
	{
		if ($this->has_freqtable())
			return $this->freqtable_record;
		else
			return false;
	}
	
	
	/**
	 * Sets up the freqtable record variable. 
	 */
	private function setup_freqtable_record()
	{
		/* ATM we check by user/corpus/name; later we should perhaps do it by ID wihtin a  scope column? */
		$sql = "select * from saved_freqtables where query_scope = '{$this->id}'";
		$result = do_mysql_query($sql);
		if (0 < mysql_num_rows($result))
			$this->freqtable_record = mysql_fetch_object($result);
		else
			$this->freqtable_record = false;
		
		return true;
	}
	
	
	/*
	 * ==============
	 * MODIFY METHODS
	 * ==============
	 */
	
	/**
	 * Returns true if the currently active user is the owner of the subcorpus.
	 * 
	 * Will also return true if the user is a superuser.
	 * 
	 * Note: it is the responsibility of the calling script to check this. 
	 * The Subcorpus class never does.
	 */
	public function owned_by_user()
	{
		global $User;
		if ($User->is_admin())
			return true;
		else if ($this->user == $User->username)
			return true;
		else 
			return false;
	}

	/**
	 * Support func: deletes any data that is based on this subcorpus' definition.
	 * 
	 * Freq tables.... saved/cached queries.... the lot.
	 * 
	 * This is used in two situations: (1) the SC is about to be edited, so the version of the SC the dependent data 
	 * refers to is about to disap[pear. (2) the Sc is beign deleted. 
	 */
	private function wipe_dependent_data()
	{
		/* When there is a dumpfile associated with an SC, delete it from disk. */
		if (file_exists($path = $this->generate_dumpfile_path()))
			unlink($path);

		/* if this subcorpus has a compiled freq table, delete it */		
		if ($this->has_freqtable())
			delete_freqtable($this->freqtable_record->freqtable_name);
		$this->freqtable_record = NULL;
		

		/* delete any DBs based on this subcorpus */
		$result = do_mysql_query("select dbname from saved_dbs where query_scope = '$this->id'");
		while ( false !== ($r = mysql_fetch_row($result)) )
			delete_db($r[0]);

		
		/* delete any queries that use this subcorpus */
		$result = do_mysql_query("select query_name, saved from saved_queries where query_scope = '{$this->id}'");
		while ( false !== ($r = mysql_fetch_row($result)) )
		{
			delete_cached_query($r[0]);
			if (CACHE_STATUS_CATEGORISED == $r[1])
				do_mysql_query("delete from saved_catqueries where catquery_name = '{$r[0]}'");
			/* the DB with the categorisationd at a was already deleted -  above. SO, here we just remove the now-orphaned record. */
		}
		
// Maybe just straight delete cached queries BUT switch the scope of saved/categorised queries to involve "arbitrary scope"?? 
// or a special flag "a deleted subcorpus????" which would mean collcoation, distribution etc. would have to recognise that flag
// and use the whole-corpus freq tables and not attempt to interrogate the subcorpus. .............. shelve that one for later. Edge case.
// it is worthwhile for "query history" but not for saved queries: they're just gone. 
// so this is a not-really-a-todo.

		
		/* last of all: overwrite this subcorpus in query history with a special flag meaning "this query ran
		 * in a subcorpus that has since been deleted". (Note that the query history view code picks up on this!)
		 * 
		 * This special flag can never match an integer ID; it is not used anywhere except in the query history! 
		 */
		do_mysql_query("update query_history set query_scope = '" . QueryScope::$DELETED_SUBCORPUS . "' where query_scope = '{$this->id}'");
		
	}
	
	
	/** Downgrades the representation of the subcorpus from a Restriction to the list of items that that Restriction brinds about. */
	private function modify_downgrade_restriction()
	{
		/* can't downgrade the restrictions unless we're using that mode... */
		if ($this->mode != self::MODE_RESTRICTION)
			return;
		
		$this->assure_restriction();
		
		/* currently, Restriction can only give us a list of items if it's text-metadata 
		 * (since there are a LOT of imponderables to contend with if it's an xml-unit). 
		 * 
		 * SO -- we test if it's text metadata, and if it is, we go to a list of text_ids.
		 * 
		 * Of it's not, then we assume it is arbitrary cpos, and get the Restriction to write 
		 * out its cpos collection to file -- then that's our data, and we are in arbitrary mode.
		 */
		
		$check_type = $check_identifier = NULL;
		
		$this->item_list = $this->restriction->get_item_list($check_type, $check_identifier);
		
		if (false === $this->item_list)
		{
			/* we weren't able to get an item list from the Restriction. */
			unset($this->item_list, $this->item_type, $this->item_identifier);
			
			/* we have to assure that the dumpfile exists from the Restriction data before losing the Restriction object. */
			$path = $this->get_dumpfile_path();
			/* but the number of merged-adjacent cpos regions MAY NOT be equal to the num of items 
			 * that we got from the Restriction object when it contained conditions. So, recount. */
			$source = fopen($path);
			$this->n_items = 0;
			while (false !== fgets($source))
				$this->n_items++;
			
			$this->mode = self::MODE_ARBITRARY;
		}
		else
		{
			/* we DID get an item list from the Restriction, so fill in variables from the restriction. */
			$this->item_type = $check_type;
			$this->item_identifier = $check_identifier;
			$this->mode = self::MODE_LIST;
			/* in this case, the num of items and the num of tokens has not changed here.  */
		}
		
		/* we're done with the Restriction  object now so release it. */
		$this->restriction = NULL;
		$this->update_content();
		
		/* and that's all: we are now downgraded from Restriction to List or Arbitrary,.  */
	}
	
	
	/**
	 * Change the item list of the subcorpus' content to the provided array of items. 
	 * 
	 * (Currently only works with text IDs.)
	 * 
	 * This function underlies a number of other modify methods. 
	 * 
	 * Note this does not auto save - none of the modify methods do. 
	 * 
	 * @param string $new_item_type
	 * @param string $new_item_identifier
	 * @param array $new_list  The array of items the subcorpus should have AFTER the modification.
	 */
	private function modify_item_list($new_item_type, $new_item_identifier, $new_list)
	{
		$this->wipe_dependent_data();
		$this->content = NULL;
		$this->restriction = NULL;
		
		/* now, re-use the populate function. */
		$this->populate_from_list($new_item_type, $new_item_identifier,  $new_list); 
		/* ... which re-sets the $content string for the db, and also re-sets the subcorpus size measures appropriately. */
	}
	
	/**
	 * Change the Subcorpus's item list by adding to its item list a set of items named in the array supplied.
	 * 
	 * @param array $items_to_add
	 */
	public function modify_add_items($items_to_add)
	{
		$this->modify_downgrade_restriction();
		
		$existing = $this->get_item_list();
		
		$new_list = array_unique(array_merge($existing, $items_to_add));
		
		sort($new_list);
		$this->modify_item_list($this->item_type, $this->item_identifier, $new_list);
	}
	
	
	/**
	 * Change the Subcorpus's item list by removing from its item list a set of items named in the array supplied.
	 * 
	 * @param array $items_to_remove
	 */
	public function modify_remove_items($items_to_remove)
	{
		$this->modify_downgrade_restriction();
		
		$existing = $this->get_item_list();
		
		$new_list = array();
		foreach($existing as $e)
			if (! in_array($e, $items_to_remove))
				$new_list[] = $e;
		
		sort($new_list);
		$this->modify_item_list($this->item_type, $this->item_identifier, $new_list);
	}
	
	
	
	/*
	 * ===================
	 * SAVE/DELETE METHODS
	 * ===================
	 */
	
	
	/**
	 * Commits any changes to the DB to the database (creating an entry if this is a new SC). 
	 */
	public function save()
	{
		if (is_null($this->id))
		{
			/* this SC does not exist in the DB: insert */
			
			/* before insert, delete any pre-existing SC. */
			$this->delete_sc_with_same_name();
			
			do_mysql_query(
				"INSERT INTO saved_subcorpora 
					(name,          corpus,            user,            content,            n_items,        n_tokens)
							values 
					('$this->name', '{$this->corpus}', '{$this->user}', '{$this->content}', $this->n_items, $this->n_tokens)"
				);

			$this->id = get_mysql_insert_id();
		}
		else
		{
			/* this SC does exist in the DB: update */
			do_mysql_query(
				"update saved_subcorpora set
									name = '{$this->name}',
									corpus = '{$this->corpus}',
									user = '{$this->user}',
									content = '{$this->content}',
									n_items = {$this->n_items},
									n_tokens = {$this->n_tokens}
								where id = {$this->id}"
				);
		}
	}
	

	/**
	 * Ancillary func for save: deletes an existing saved SC where the name/corpus/user match.
	 * 
	 * Returns true if an SC was found and deleted; else false.
	 */
	private function delete_sc_with_same_name()
	{
		if (false !== ($delenda = self::new_from_name($this->name, $this->corpus, $this->user)))
			return $delenda->delete();
		else
			return false;
	}

	/**
	 * Delete the subcorpus from the database. Returns true for success, false for failure.
	 */
	public function delete()
	{
		$this->wipe_dependent_data();

		/* and finally, get rid of the actual entry in the subcorpus table. */
		do_mysql_query('delete from saved_subcorpora where id = ' . $this->id);

		return true;
	}
	
	
	
	
	
	/*
	 * =======================
	 * CQP-INTERFACING METHODS
	 * =======================
	 */
	
	/** Activate this subcorpus AS a subcorpus within the global CQP child process. */
	public function insert_to_cqp()
	{
		global $cqp;
		
		/* calling the func below assures the dumpfile at that path exists */
		$file = $this->get_dumpfile_path();
		
		$cqp->undump_file("Sc-{$this->id}", $file);
		$cqp->execute("Sc-{$this->id}");
	}
	
	
	/** Gets the path of this subcorpus's dumpfile: assuring, in the process, that it exists. 	 */
	public function get_dumpfile_path()
	{
		/* Does a dumpfile already exist for this SC? */
		$path = $this->generate_dumpfile_path();
		
		if (! file_exists($path))
			$this->create_dumpfile();
		
		return $path;
	}
	
	
	/** 
	 * This is a separate function so that we avoid repeating the convention. 
	 * The corresponding public function, which uses this, ALSO does the write-out if necessary.
	 */
	private function generate_dumpfile_path()
	{
		/* the convention for dumpfi8le names is as follows: 
		 * "scdf-" plus the SC's id, in hex, padded to 5 w/ printf-style. 
		 */
		global $Config;
		return $Config->dir->cache . sprintf('/scdf-%05x', $this->id);
	}
	
	
	
	/**
	 * Writes this subcorpus out to a file in the cache directory, as a CQP-dumpfile.
	 * 
	 * Note that this is unconditional -- no check for whether the dumpfile exists or not. 
	 * 
	 * If it does exist it will be overwritten.
	 * 
	 * @return bool   True if func was able to write a file (or assure its existence);
	 *                otherwise false.
	 */
	private function create_dumpfile()
	{
		$outpath = $this->generate_dumpfile_path();
				
		switch ($this->mode)
		{
		case self::MODE_ARBITRARY:
			/* if this is an arbitrary-cpos-data subcorpus, we already have the dumpfile ---
			 * it's the canonical locus of the arbitrary pairs; so just check it really exists.  */ 
			return file_exists($outpath);
			
			
		case self::MODE_NOT_POPULATED:
			return false;
			
			
		case self::MODE_LIST:
			
			/* ITEM LIST insert */

			$dest = fopen($outpath, 'w');
			
			if ($this->item == 'text')
			{
				$wherelist = translate_itemlist_to_where($this->text_list);
				
				$result = do_mysql_query("SELECT cqp_begin, cqp_end FROM text_metadata_for_{$this->corpus} WHERE $wherelist ORDER BY cqp_begin ASC");
				
				$buf = NULL;
				while (false !== ($r = mysql_fetch_object($result)))
				{
					/* the use of a buffer object allows us to merge adjacent ranges. */ 
					if (empty($buf))
						$buf = $r;
					else
					{
						if ($buf->cqp_end >= ($r->cqp_begin-1))
							$buf->cqp_end = $r->cqp_end;
						else
						{
							fputs($dest, "{$buf->cqp_begin}\t{$buf->cqp_end}" . PHP_EOL);
							$buf = $r;
						}
					}
				}
				/* and write the contents of the buffer at loop end */
				if (!empty($buf))
					fputs($dest, "{$buf->cqp_begin}\t{$buf->cqp_end}" . PHP_EOL);
			}
			else
			{
				$s_att_to_scan = $this->item_type . (empty($this->item_identifier) ? '' : '_' . $this->item_identifier);
				
				$source = open_xml_attribute_stream($this->corpus, $s_att_to_scan);
				
				$seqpos = 0;
				
				$buf = NULL;
				while (false !== ($l = fgets($source)))
				{
					$r = explode("\t", trim($l));
					
					/* identifier set: it's an attribute with IDs: so check the 3rd value against the item list */
					/* identifier not set: it's an attribute wihtout IDs: so check the seqpos against the item list */
					$check = ('' != $this->item_identifier ? $r[2] : $seqpos);
					
					if (in_array($check, $this->item_list))
					{
						if (empty($buf))
							$buf = $r;
						else
						{
							if ($buf[1] >= ($r[0]-1))
								$buf[1] = $r[1];
							else
							{
								fputs($dest, "{$buf[0]}\t{$buf[1]}" . PHP_EOL);
								$buf = $r;
							}
						}
					}

					++$seqpos;
				}
				/* and write the contents of the buffer at loop end */
				if (!empty($buf))
					fputs($dest, "{$buf[0]}\t{$buf[1]}" . PHP_EOL);
				
				pclose($source);
			}
			
			fclose($dest);
			
			break;
			
			
		case self::MODE_RESTRICTION:
			
			/* TEXT RESTRICTIONS insert */
			
			$this->assure_restriction();
			$this->restriction->create_dumpfile($outpath);
			break;
			
		default:
			exiterror("The data record for subcorpus {$this->name} (ID: {$this->id}) seems to be corrupt!");
			/* ACTUALLY, not reached. */
		}
		
		return true;
	}
	
	
	
} 
/* 
 * ====================== 
 * end of class Subcorpus 
 * ====================== 
 */








/*
 * =======================================================
 * QUERYSCOPE, RESTRICTION AND SUBCORPUS SUPPORT FUNCTIONS
 * =======================================================
 */






/*
 * Restriction cache functions
 * ===========================
 * 
 * The restriction cache contains a cache of cpos data for recently used XML-based (or mixed) restrictions.
 * 
 * These functions are only used by the Restriction object, but they are external to it as a copmplexity-of-class reduction measure.
 */

/**
 * Add the content of a restriction to the restriction cache.
 * 
 * The assorted arguments all correspond to critical elements of the Restriction that are private variables within it. 
 * 
 * @param string $corpus           The corpus the restriction exists in.
 * @param string $serialisation    Serialised representation of the restriction.
 * @param int $n_items             Number of items in the restriction.
 * @param int $n_tokens            Number of tokens in the restriction.
 * @param array $cpos_collection   Array of arrays, each containing a cpos begin and a cpos end. 
 */
function add_restriction_data_to_cache($corpus, $serialisation, $n_items, $n_tokens, $cpos_collection)
{
	/* data base make safe..... */
	$corpus = mysql_real_escape_string($corpus);
	$serialisation = mysql_real_escape_string($serialisation);
	$n_items  = (int)$n_items;
	$n_tokens = (int)$n_tokens;
	
	$timenow = time();
	$blob = mysql_real_escape_string(translate_restriction_cpos_to_db($cpos_collection));
	
	$sql = "insert into saved_restrictions 
				(cache_time, corpus,    serialised_restriction, n_items,  n_tokens, data) 
					values 
				($timenow,   '$corpus', '$serialisation',       $n_items, $n_tokens, '$blob')";
	do_mysql_query($sql);
}


/**
 * Delete the specified restriction from cache.
 * 
 * @param int $id  Database identifier of the row to be deleted.
 */
function delete_restriction_from_cache($id)
{
	$id = (int) $id;
	do_mysql_query("delete from saved_restrictions where id = $id");
}



/**
 * Delete excess entries from the restriction cache to bring it back under the size limit.
 */
function delete_restriction_overflow()
{
	global $Config;
	
	if ($Config->restriction_cache_size_limit > get_mysql_table_size("saved_restrictions"))
		return;
	
	$result = do_mysql_query("select id from saved_restrictions order by cache_time asc");
	
	while (false !== ($o = mysql_fetch_object($result)))
	{
		delete_restriction_from_cache($o->id);
		
		/* Alas, we have to call the size-me-up function after each loop, because we can't work out how much we've dropped
		 * the size of the table by deleting a single row, as that does not take into account the index. */
		if ($Config->max_cached_restrictions > get_mysql_table_size("saved_restrictions"))
			return;
		
		/* possible race condition: if new ones are cached by a parallel instance 
		 * while this loop is running, we will still be over the limit.
		 * But this should only be a temporary state. */
	}
}

/**
 * Check the restriction cache for data matching a given serialised restriction. 
 * 
 * @param string $corpus         Corpus the restriction occurs within.
 * @param string $serialisation  A serialised restriction. 
 * @return stdClass              Database object containing members id, n_items, n_tokens, data for the retrieved cache object.
 *                               Or, boolean false if nothing matching the serialised restriction is found.
 */
function check_restriction_cache($corpus, $serialisation)
{
	$corpus = mysql_real_escape_string($corpus);
	$serialisation = mysql_real_escape_string($serialisation);
	
	$result = do_mysql_query("select id, n_items, n_tokens, data from saved_restrictions
								where corpus='$corpus' and serialised_restriction='$serialisation'");
	
	if (0 < mysql_num_rows($result))
		return mysql_fetch_object($result);
	else
		return false;
}

/**
 * Touches a cached restriction setting its cache time to the present time.
 * 
 * @param int $id  Database ID of the cache entry to touch.
 */
function touch_restriction_cache($id)
{
	$id = (int) $id;
	$newtime = time();
	do_mysql_query("update saved_restrictions set cache_time = $newtime where id = $id");
}




/**
 * Takes the blob of the "data" entry in the restriction cache, and converts it back to a cpos collection.
 * 
 * @param unknown $data  Data blob from the saved_restrictions db table.
 * @return array         Array of arrays where each inner array is a cpos pair indexed [0,1].
 */
function untranslate_restriction_cpos_from_db($data)
{
	$cpos_collection = array();
	
	for($i = 0, $n = strlen($data)/8; $i < $n; $i++)
		$cpos_collection[$i] = array_values(unpack("V*", substr($data, $i*8, 8)));

	return $cpos_collection;
}


/**
 * Takes an array of cpos pairs, as per the processed internal content of a Restriction, 
 * and converts it to a binary string (8 bytes per pair; packed as V = unsigned long little endian).
 * 
 * @param array $cpos_collection  Cpos collection to translate.
 * @return string                 Binary string containing packed integers.
 */
function translate_restriction_cpos_to_db($cpos_collection)
{
	$data = '';

	foreach($cpos_collection as $pair)
		$data .= pack("VV", $pair[0], $pair[1]);
	
	return $data;
}

//TODO check the round trip.



/*
 * Now we are on to SUBCORPUS SUPPORT FUNCTIONS
 *                  ===========================
 */



/**
 * Returns amount of cache folder (in bytes) taken up by the cached undump files of subcorpora.
 * 
 * Used in cache management.
 */
function subcorpus_dumpfile_disk_usage()
{
	return array_sum(subcorpus_dumpfile_scan_cache());
}


/** Returns an array of the subcorpus dump files in the cache folder (as keys; basename NOT full path) mapped to their sizes in bytes. */ 
function subcorpus_dumpfile_scan_cache()
{
	global $Config;
	
	$files = glob("{$Config->dir->cache}/scdf-*");
	
	$list = array();
	foreach ($files as $f)
		$list[basename($f)] = filesize($f);
	return $list;
}



/**
 * Gets an array containing the names of all subcorpora belonging to the current user in the current corpus.
 * 
 * Note this will EXCLUDE the special --last-restrictions unless true is passed
 */
function get_list_of_subcorpora($include_last_restrictions = false)
{
	global $User;
	global $Corpus;

	$exclude = ($include_last_restrictions ? '' : " and name != '--last_restrictions'");
	
	$result = do_mysql_query("select name from saved_subcorpora where user='{$User->username}' and corpus='{$Corpus->name}'$exclude");
	
	for ($list = array() ; false !== ($r = mysql_fetch_row($result)) ; )
		$list[] = $r[0];
	
	return $list;
}



/**
 * Gets an array that maps integer IDs to subcorpus names 
 * (for printing - for when calling the Subcorpus constructor
 * just to get a printable name is too heavyweight).
 * 
 * If the $corpus argument is not provided, a list from 
 * across all corpora is provided.
 * 
 *  Either way, the names need ot be assumed to be non-unique.
 */
function get_subcorpus_name_mapper($corpus = NULL)
{
	if (! empty($corpus))
	{
		$corpus = mysql_real_escape_string($corpus);
		$where = "where corpus = '$corpus'";
	}
	else
		$where = '';
	
	$result = do_mysql_query("select id, name from saved_subcorpora $where");
	
	$mapper = array();
	
	while (false !== ($o = mysql_fetch_object($result)))
		$mapper[$o->id] = $o->name;
	
	return $mapper;
}









/**
 * Sorts an array of arrays representing a sequence of CWB corpus positions.
 * 
 * The array is sorted by ascending value of the [0] element of each inner array.
 * 
 * Note, unlike the normal PHP array sort functions, this function uses pass-by-value
 * and return. It does not operate on a variable passed by reference.
 * 
 * @return  The sorted array.
 */
function sort_positionlist($list)
{
	usort($list, 
			function ($a, $b) 
			{
				if ($a[0] == $b[0]) return 0; 
				return ( ($a[0] < $b[0]) ? -1 : 1 );
			}
		);
	
	return $list;
}





/**
 * Translates a list of text IDs (or, item IDs more generally) to a condition 
 * usable in a where clause, listing each text as an or-linked condition on 
 * the text_id field (by default: any other field can be supplied as the 
 * third argument; this function does not actually access MySQL, so what table
 * the fieldname belongs to is irelevant).
 * 
 * Note the actual "WHERE" keyword is not included in the return value.
 * 
 * The argument (item_list) is normally expected to be a string of
 * space-delimited text ids. But, if the (optional) second parameter
 * is set to true, the first argument will instead be expected to be
 * an array of strings where each one is a single text id.
 * 
 * (Although associated with subcorpora and restrictions, this is more 
 * of a general utility function than a method that belongs on either object.)
 */
function translate_itemlist_to_where($item_list, $as_array = false, $fieldname = 'text_id')
{
	$fieldname = cqpweb_handle_enforce($fieldname);

	if ($as_array)
		$list = $item_list;
	else
		$list = explode(' ', $item_list);
	//low-importance-TODO. why not use is_array / is_string instead?
	
	return "($fieldname='" . implode("'||$fieldname='", $list) . "')";
}




/**
 * Pass it an array of text IDs: get back an array
 * containing text IDs that don't exist in the corpus.
 * 
 * If corpus not specified as second argument then 
 * the global $Corpus obj is assumed.
 * 
 * If there are no badnames the return is an empty array 
 */
function check_textlist_valid($text_list, $corpus = NULL)
{
	$badnames = array();
	foreach($text_list as $id)
		if (!check_real_text_id($id, $corpus))
			$badnames[] = $id;
	return $badnames;
// a n ote. might it not be quicker to get the entire list of text_ids using corpus_list_texts()? Then in_Array?
}





/**
 * Returns true if the first argument is a text that exists in the present corpus
 * (or in a corpus supplied as an argument).  
 * 
 * If it is not, then function returns false.
 */ 
function check_real_text_id($text_id, $c = NULL)
{
	if (empty($c))
	{
		global $Corpus;
		$c = $Corpus->name;
	}
	else
		$c = mysql_real_escape_string($c);

	$text_id = mysql_real_escape_string($text_id);
	
	$sql = "select text_id from text_metadata_for_$c where text_id = '$text_id'";
	
	return ( 0 < mysql_num_rows(do_mysql_query($sql)) );
}


/**
 * Opens a readable pipe to cwb-s-decode for the specified attribute on the specified corpus.
 * 
 * TODO delete this func - see note below.
 * 
 *  Utility function for Subcorpus / Restriction.
 */
function get_s_decode_stream($att, $with_values, $corpus = NULL)
{
	global $Config;
	global $Corpus;

	if (is_null($corpus) )
		$corpus = $Corpus->name;
	
	if ($corpus == $Corpus->name)
		$uc_corpus = $Corpus->cqp_name;
	else
	{
		$c_info = get_corpus_info($corpus);
		$uc_corpus = $c_info->cqp_name;
	}
	
	$val = ($with_values ? '' : '-v');
	
	if (false === ($pipe = popen("{$Config->path_to_cwb}cwb-s-decode $val -r \"{$Config->dir->registry}\" $uc_corpus -S $att", "r")))
		exiterror("Could not open pipe to cwb-s-decode!!");
	
	return $pipe;
}

/// TODO. the fubnc above dupplicates one I already had (in xml.inc ---> for reference, a copy is in comments that follow.)
//  The func above should be removed!!!!!!
// /*
//  * Gets a readable stream resource to cwb-s-decode for 
//  * the underlying s-attribute of the specified XML.
//  * 
//  * Exits with an error if opening of the stream fails.
//  * 
//  * The resource returned can be closed with pclose().
//  */ 
// function open_xml_attribute_stream($corpus, $att_handle)
// {
// 	if (false === ($c = get_corpus_info($corpus)))
// 		exiterror("Cannot open xml attribute stream: corpus does not exist.");
	
// 	if (false === ($x = get_xml_info($corpus, $att_handle)))
// 		exiterror("Cannot open xml attribute stream: specified s-attribute does not exist.");

// 	/* the above also effectively validates the arguments  */

// 	global $Config;
	
// 	$cmd = "{$Config->path_to_cwb}cwb-s-decode -r {$Config->dir->registry} {$c->cqp_name} -S $att_handle";
	
// 	if (false === ($source = popen($cmd, "r")))
// 		exiterror("Cannot open xml attribute stream: process open failed for ``$cmd'' .");

// 	return $source;
// }
//TODO
//TODO
//TODO
//TODO
//TODO
//TODO
//TODO


