#!/bin/bash
# Build script for Eighty/20 Results - E20R Payment Warnings for PMPro
#
short_name="e20r-payment-warning-pmpro"
svn_target="e20r-payment-warning-pmpro"
svn_directory="/Users/sjolshag/PhpStormProjects/svn/"
svn_full_path="${svn_directory}/${svn_target}/trunk/"
server="eighty20results.com"
include=(class css js languages class-${short_name}.php readme.txt)
exclude=(*.yml *.phar composer.* vendor lib)
sed=/usr/bin/sed
build=(plugin-updates/vendor/*.php)
plugin_path="${short_name}"
version=$(egrep "^Version:" ../class-${short_name}.php | sed 's/[[:alpha:]|(|[:space:]|\:]//g' | awk -F- '{printf "%s", $1}')
metadata="../metadata.json"
src_path="../"
dst_path="../build/${plugin_path}"
kit_path="../build/kits"
kit_name="${kit_path}/${short_name}-${version}"
debug_name="${kit_path}-debug/${short_name}-debug-${version}"
debug_path="../build/${plugin_path}-debug"

echo "Building kit for version ${version} in ${svn_full_path}"

mkdir -p ${svn_full_path}

for p in ${include[@]}; do
	cp -R ${src_path}${p} ${svn_full_path}
done

for e in ${exclude[@]}; do
    find ${svn_full_path} -type d -iname ${e} -exec rm -rf {} \;
done

cd ${svn_full_path}
echo "Remove One-click update support"
perl -i -0777 -p -e 's/\s\/\*\* One-Click update support \*\*\/.*\/\*\* End of One-Click update support \*\*\/\s//ms;' class.${short_name}.php
