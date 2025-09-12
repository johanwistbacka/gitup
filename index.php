<?php
/*
Plugin Name: Rätt Grafiska Git Updater
Description: Hanterar automatiska uppdateringar för Ratt Grafiskas plugins via GitHub.
Version: 2025.09.12.02-beta
Author: Ratt Grafiska
Plugin URI: https://github.com/Ratt-Grafiska/rg-git-updater
Update URI: https://github.com/Ratt-Grafiska/rg-git-updater
*/
// Kontrollera om huvudklassen redan är laddad (skydd mot dubbel-inkludering)
if (!class_exists("RgGitUpdaterClass")) {

    // Ladda själva uppdateringsmotorn och options-sidan
    require_once plugin_dir_path(__FILE__) . "rg-git-updater.php";
    require_once plugin_dir_path(__FILE__) . "options.php";

}