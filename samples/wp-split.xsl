<?xml version="1.0" encoding="UTF-8" ?>
<xsl:stylesheet version="2.0"
		xmlns:xsl="http://www.w3.org/1999/XSL/Transform">

<xsl:param name="size" />
<xsl:param name="page" />
<xsl:param name="output" />

<xsl:template match="/rss">
	<xsl:result-document method="xml" href="{$output}_{$page * $size}-{($page + 1) * $size - 1}.xml">
			<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.1/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.1/">

			<channel>
				<xsl:for-each select="channel/*[local-name() != 'item']">
					<xsl:copy-of select="." />

				</xsl:for-each>
			
				<xsl:for-each select="channel/item">
					<xsl:if test="position() &lt; ($page + 1) * $size and position() &gt;= $page * $size">
						<xsl:copy-of select="." />
					</xsl:if>
				</xsl:for-each>
			</channel>
		</rss>
	</xsl:result-document>
</xsl:template> 

</xsl:stylesheet>
