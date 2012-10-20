#!/bin/bash

# filename: required
file=$1
outfile=$2

if [ "$file" = "" ] || [ ! -f $file ] || [ "$outfile" = "" ]; then
   echo "Usage: $0 filename outfile [pagesize] [start] [limit]"
   exit 1
fi

# page size: defaults to 2000
[ "$3" != "" ] && pagesize=$3 || pagesize=2000

# start post: defaults to 0 (first post)
[ "$4" != "" ] && start=$4 || start=0

# limit: defaults to # of posts in input file
[ "$5" != "" ] && limit=$5 || limit=`grep '<item>' $file | wc -l`

echo "Splitting $file into" `echo "($limit-$start)/$pagesize" | bc` "pages of size $pagesize between posts $start and $limit";

i=$start

while [ "$i" -le "$limit" ]; do
	echo "Generating page $((i/pagesize)): posts $((i)) through $((i+pagesize)).."; 
	java -Xmx2000m -jar ~/saxonhe9-2-0-5j/saxon9he.jar -xsl:split.xsl $file page=$((i/pagesize)) size=$pagesize output=$outfile
	i=$((i+pagesize))
done

