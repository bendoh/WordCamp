#!/usr/local/bin/perl

use strict;
use Getopt::Std;

sub usage {
	my $msg = shift;
	print STDERR ">> $msg\n" if $msg;
	die <<"EOU";
Usage: $0 -h hostname [-s start_date]
  -h hostname  - Hostname of Wordpress instance
  -s start_date - Start exporting posts from start_date
EOU
}

my (%opts);

getopts('h:s:', \%opts);

if( !$opts{'h'} ) {
	&usage("-h option required");
}
if( $opts{'s'} && $opts{'s'} !~ /^\d{4}-\d{2}-\d{2}$/ ) {
	&usage("-s option requires YYYY-MM-DD date format");
}

open( BLOGS, "php wp-export.php -h \"$opts{'h'}\" 2>/dev/null |" ) or die "Could not blogs open wp-export.php!";

while(<BLOGS>) {
	chomp;
	my( $blogid, $blog ) = split(/\s+/);
	
	my $cmd = "php wp-export.php -h \"$opts{'h'}\" -b $blogid";
	$cmd .= " -s \"$opts{'s'}\"" if $opts{'s'};
	$cmd .= " >export/$blog";
	$cmd .= "-\"$opts{'s'}\"" if $opts{'s'};
	$cmd .= ".xml 2>/dev/null";

	print "Exporting blog #$blogid, $blog ($cmd)... ";
	system($cmd);
	print "Done.\n";
}
