Wikisource Search
=================

A tool to search through book data in Wikidata and Wikisource.

See it in action at http://tools.wmflabs.org/ws-search

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/wikisource/ws-search/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/wikisource/ws-search/?branch=master)
[![Build Status](https://scrutinizer-ci.com/g/wikisource/ws-search/badges/build.png?b=master)](https://scrutinizer-ci.com/g/wikisource/ws-search/build-status/master)
[![License](https://img.shields.io/github/license/wikisource/ws-search.svg?style=flat-square)](https://github.com/wikisource/ws-search/blob/master/LICENSE.txt)

## Install

1. Clone from git:<br />`git clone https://github.com/wikisource/ws-search.git`
2. Update dependencies: `composer update`
3. Edit the `.env.local` configuration file
5. Run the upgrade script: `./bin/console upgrade`

## Upgrade

1. Update code: `git pull origin master`
2. Update dependencies: `composer update`
3. Run the upgrade script: `./bin/console upgrade`

## Administer

1. Populate the list of Wikisources:<br /> `./bin/console scrape langs`
2. Populate with all existing data for a given Wikisource:<br /> `./bin/console scrape --lang=en`
3. Keep up to date with recent changes on that Wikisource:<br /> `./bin/console rc --lang=en`

To automatically keep up to date, run the first two scrape commands above
and then add the RecentChanges command as a cronjob:<br /> `./bin/console rc`

## Contributing

Development is managed on GitHub at https://github.com/wikisource/ws-search
