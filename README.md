# Vcard Scripts
A collection of scripts for DoSomething Lose Your Vcard campaign

## Requirements
- PHP 5.6
- php56-redis
- composer
- Redis 3.2

## Setup
- `composer install`
- `cp .env.example .env`
- Update .env settings
- `mkdir log`

## Usage
### Step 1: Save all MoCo profiles to Redis
```
Usage:
  1-get-users-from-moco.php [options]

Options:
  -p, --page <int>                        MoCo profiles start page, defaults to 1
  -l, --last <int>                        MoCo profiles last page, defaults to 0
  -b, --batch <1-1000>                    MoCo profiles batch size, defaults to 100
  -s, --sleep <0-60>                      Sleep between MoCo calls, defaults to 0
  --test-phones <15551111111,15551111112> Comma separated phone numbers. Intended for tests
  -h, --help                              Show this help
```

### Step 2: Match MoCo users to Northstar and generate new fields
```
Usage:
  2-generate-links.php [options]

Options:
  -f, --from <int> Last element to load, default 0
  -t, --to <int>   First element to load
  -u, --url <url>  Link base url. Defaults to https://www.dosomething.org/us/campaigns/lose-your-v-card
  -h, --help       Show this help
```

### Step 3: Update MoCo profiles
```
Usage:
  3-save-profile-updates-to-moco.php [options]

Options:
  -f, --from <int> Last element to load, default 0
  -t, --to <int>   First element to load
  -h, --help       Show this help
```

### Benchmarks
##### Batch 100, pages 30
```
$ time php 1-get-users-from-moco.php -l 30 -b 100
3000/3000 [==============================================>] 100.00% 00:00:00
php 1-get-users-from-moco.php -l 30 -b 100  2.59s user 0.54s system 2% cpu 2:16.96 total
```
Result: 623 users.

##### Batch 1000, pages 3
```
$ time php 1-get-users-from-moco.php -l 3 -b 1000
3000/3000 [==============================================>] 100.00% 00:00:00
php 1-get-users-from-moco.php -l 3 -b 1000  2.01s user 0.42s system 2% cpu 1:23.68 total
```
Result: 623 users.
