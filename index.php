<?php
/*
Plugin Name: Rätt Grafiska Git Updater
Description: Hanterar automatiska uppdateringar för Ratt Grafiskas plugins via GitHub.
Version: 1.0.2
Author: Ratt Grafiska
Plugin URI: https://github.com/Ratt-Grafiska/rg-git-updater
Update URI: https://github.com/Ratt-Grafiska/rg-git-updater
*/
// Initiera uppdateraren
if (!class_exists("RgGitUpdater")) {

require_once plugin_dir_path(__FILE__) . "rg-git-updater.php";
require_once plugin_dir_path(__FILE__) . "options.php";

}