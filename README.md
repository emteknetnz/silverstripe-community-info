# Github community info

Generate csvs that contain info on silverstirpe community open pull-requests and issues

Data is sourced from the github API

## Setup

Create `.credentials`

```
user=my_github_username
token=abcdef123456abcdef123456abcdef123456abcd
travis_org_token=xyzdef123456abcdef123456abcdef123456abcd
```

token is a github token. Even though we're only accessing publically acessible information, you'll still want a github token so that you won't get rate limited

Setup a token on you github account https://github.com/settings/tokens/new
```
[x] Access commit status 
[x] Access public repositories 
```

travis_org_token can be found at https://travis-ci.org/account/preferences.  It's important you use the token from from the .org site not the .com site which behaves differently and will include your private repos.

## Usage

### Fetch latest data from github and output a csv to the csv directory
```
php openprs.php
php issues.php
php travis.php
```

### Re-use local data if available (useful for development)
```
php openprs.php -l (or --local)
php issues.php -l (or --local)
php travis.php -l (or --local)
```

### Making use of the csv output in a google spreadhseet
- Open a new sheet in google spreadsheets https://sheets.google.com
- Copy paste in the contents of csv/openprs.csv or csv/issues.csv into cell A1
- Data > Split text to columns
- Data > Filter

### Updating the data in an existing google spreadsheet
- Data > Filter (to turn off the filter)
- Delete all the existing data
- Paste in the latest csv data, splits text to columns and filter
