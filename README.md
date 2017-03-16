# Mobile Commons Profiles Back up script
This version of the Vcard Scripts is used to **ONLY** back up MoCo profiles to a local running mongodb instance

## Requirements
- PHP 5.6 (brew: php56)
- MongoDB PHP driver (brew: php56-mongodb)
- mongodb (brew: mongodb)
- GNU time (brew: gnu-time) **Optional** _(better stats)_
- GNU parallel (brew: parallel) **Optional** _(parallel processes)_
- composer

## Setup
- Install mongodb using brew.
  - type `mongo` in your CLI. If no errors, 👍. Otherwise, open a new tab and run `mongod`
- Install `php56` and `php56-mongodb` PHP driver using brew.
  - Install notes are in the **NOTES** section.
- `composer install`
- `cp .env.example .env`
- Update .env settings
- `mkdir log`

## Usage
### Save all MoCo profiles to mongodb
```
Usage:
  php 1-get-users-from-moco.php [options]

Options:
  -p, --page <int>                        MoCo profiles start page, defaults to 1
  -l, --last <int>                        MoCo profiles last page, defaults to 0
  -b, --batch <1-1000>                    MoCo profiles batch size, defaults to 100
  -h, --help                              Show this help
```

### Benchmarks
##### Batch 100, pages 1
```
$ gtime php 1-get-users-from-moco.php -p 1 -l 1 -b 100
0.19user 0.11system 0:06.19elapsed 5%CPU (0avgtext+0avgdata 272023552maxresident)k
53inputs+4outputs (1270major+17785minor)pagefaults 0swaps
```
Result: 100 users.

##### Batch 100, pages 1 x 10000
##### 6 parallel processes
```
$ gtime parallel -j 6 php 1-get-users-from-moco.php -p {1} -l {1} -b 100 :::: <(seq 1 10000)
1852.75user 697.87system 2:49:02elapsed 25%CPU (0avgtext+0avgdata 430063616maxresident)k
1405inputs+97362outputs (17065major+206178620minor)pagefaults 0swaps
```
Result: 1,000,000 users.

## Notes
- [How to install php56 through brew](https://github.com/Homebrew/homebrew-php)
- [How to install php56-mongodb PHP driver through brew](http://php.net/manual/en/mongodb.installation.homebrew.php)
