#!/bin/sh

../../../bin/phpunit --display-all-issues --fail-on-deprecation --display-warnings --display-notices --display-errors --display-incomplete --process-isolation --colors --testdox --bootstrap bootstrap.php --testdox-text results.txt --testdox-html results.html ./