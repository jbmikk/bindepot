# bindepot: a file system to manage your php uploads

*  Licensed under the [GPL version 2] (http://www.gnu.org/licenses/).

## Introduction

Bindepot manages your php uploads and files in a flexible way, abstracting
away storage details and providing extra functionality and extensibility.
It lets you define different stores for different files according to your needs.

Currently there are two types of storage:

* Binary: this is plain storage and retrieval of files.
* Image: It allows storing images in different sizes and qualities.

## Configuration

There is an example xml configuration file that shows basic configuration.
The first thing you should do is create a directory with write permissions,
this is where all of your files will be stored.
Once you have your parent folder, each store definition will create it's own
subdirectory to contain all of it's files.
For example if you create and image store named 'profile' and define two
formats (100x100 and 250x120) there will be a 'profile' subdirectory and three
subdirectories inside, one for every format plus an 'original' directory to
contain the orignal uploaded file.  

## Urls

Currently defined store types use the underlying file system for storage, so
you can map any urls that you need through .htaccess directly to your storage
folder.
In the future there may be storage types that do not allow this, in such cases
you should handle requests through php, using bindepot to access your files.

