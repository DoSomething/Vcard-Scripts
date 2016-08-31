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

## Usage
### Step 1: Save all MoCo profiles to Redis
```
$ php 1-get-users-from-moco.php --help
Usage:
  1-get-users-from-moco.php [options]

Options:
  -p, --page <page>    MoCo profiles start page, defaults to 1
  -l, --last <page>    MoCo profiles last page, defaults to 0
  -b, --batch <1-1000> MoCo profiles batch size, defaults to 100
  -h, --help           Show this help
```

### Step 2: Match MoCo users to Northstar and generate new fields
TODO

### Step 3: Update MoCo profiles
TODO
