Wikisource Search
=================

A tool to search through book data in Wikidata and Wikisource.

See it in action at http://tools.wmflabs.org/ws-search

[![License](https://img.shields.io/github/license/wikisource/ws-search.svg?style=flat-square)](https://github.com/wikisource/ws-search/blob/master/LICENSE.txt)

## Install

1. Clone from git:<br />`git clone https://github.com/wikisource/ws-search.git`
2. Update dependencies: `composer update`
3. Edit the `config.php` configuration file that has been created in the ws-search directory
5. Run the upgrade script: `./cli upgrade`

## Upgrade

1. Update code: `git pull origin master`
2. Update dependencies: `composer update`
3. Run the upgrade script: `./cli upgrade`

## Administer

1. Populate the list of Wikisources:<br /> `./cli scrape langs`
2. Populate with all existing data for a given Wikisource:<br /> `./cli scrape --lang=en`
3. Keep up to date with recent changes on that Wikisource:<br /> `./cli recent-changes --lang=en`

To automatically keep up to date, run the first two scrape commands above
and then add the RecentChanges command as a cronjob:<br /> `./cli recent-changes`

## Contributing

Development is managed on GitHub at https://github.com/wikisource/ws-search
