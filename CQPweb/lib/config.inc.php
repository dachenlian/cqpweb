<?php

/* adminstrators' usernames, separated *
 * by | with no stray whitespace.      *
 * ----------------------------------- */

$superuser_username = 'admin';


/* -------------------------- *
 * database connection config *
 * -------------------------- */

$mysql_webuser = 'cqpweb';
$mysql_webpass = 'cqpweb';
$mysql_schema  = 'cqpweb';
$mysql_server  = 'localhost';



/* ---------------------- *
 * server directory paths *
 * ---------------------- */

$cqpweb_tempdir   = '/tmp/cqp';
$cqpweb_uploaddir = '/cqp/upload';
$cwb_datadir      = '/corpora/data';
$cwb_registry     = '/usr/local/share/cwb/registry/';


/* --------------- *
 * optional config *
 * --------------- */

$path_to_cwb = '/usr/local/cwb-3.4.15/bin';
$path_to_perl = '/usr/bin/perl';
$path_to_r = '/usr/bin/R';

$cwb_max_ram_usage = 1000;
