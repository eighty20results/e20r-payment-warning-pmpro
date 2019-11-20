#!/bin/bash
# Build script: Copyright 2016 Eighty/20 Results by Wicked Strong Chicks, LLC
# Payment Warning for Paid Memberships Pro (with add-on payment gateway support)
#
short_name="e20r-payment-warning-pmpro"
server="eighty20results.com"
include=(class css javascript libraries languages LICENSE templates class.${short_name}.php README.md readme.txt)
exclude=(*.yml *.phar composer.* vendor)
build=(plugin-updates/vendor/*.php)
plugin_path="${short_name}"
version=$(egrep "^Version:" ../class.${short_name}.php | sed 's/[[:space:]\*[:space:]|[:alpha:]|(|[:space:]|\:]//g' | awk -F- '{printf "%s", $1}')
metadata="../metadata.json"
src_path="../"
dst_path="../build/${plugin_path}"
kit_path="../build/kits"
kit_name="${kit_path}/${short_name}-${version}"
api_path="./api_files"
php_tool=/usr/bin/php
debug_name="${kit_path}-debug/${short_name}-debug-${version}"
debug_path="../build/${plugin_path}-debug"

echo "Building kit for version ${version}"
echo "Prepare all API files for version ${version} of the plugin"

for entry in "${api_path}"/*.txt; do
	# echo "Processing: ${entry}"
	${php_tool} ./convert-to-json.php ${entry}
done

rm ${src_path}logs/paypal.log

mkdir -p ${kit_path}
mkdir -p ${kit_path}-debug
mkdir -p ${dst_path}
mkdir -p ${debug_path}

if [[ -f  ${kit_name} ]]
then
    echo "Kit is already present. Cleaning up"
    rm -rf ${dst_path}
    rm -rf ${debug_path}
    rm -f ${kit_name}
    rm -f ${debug_name}
fi

for p in ${include[@]}; do
	cp -R ${src_path}${p} ${dst_path}
	cp -R ${src_path}${p} ${debug_path}
done

for e in ${exclude[@]}; do
    find ${dst_path} -type d -iname ${e} -exec rm -rf {} \;
done
echo "Stripping Debug data from sources"
find ${dst_path} -type d -name 'plugin-updates' -prune -o -type f -name '*.php' | xargs ${sed} -i '' "/.*->log\(.*\);$/d"

for e in ${exclude[@]}; do
    find ${dst_path} -type d -iname ${e} -exec rm -rf {} \;
    find ${debug_path} -type d -iname ${e} -exec rm -rf {} \;
done

#mkdir -p ${dst_path}/plugin-updates/vendor/
#for b in ${build[@]}; do
#    cp ${src_path}${b} ${dst_path}/plugin-updates/vendor/
#    cp ${src_path}${b} ${debug_path}/plugin-updates/vendor/
#done

for entry in "${api_path}"/*.json; do
	echo "Copying ${entry} to ${server}:./${server}/protected-content/api-versions/"
	scp ${entry} ${server}:./${server}/protected-content/api-versions/
done

cd ${dst_path}/..
zip -r ${kit_name}.zip ${plugin_path}
cd ${debug_path}/..
cd ${dst_path}/..
zip -r ${debug_name}.zip ${plugin_path}-debug
ssh ${server} "cd ./${server}/protected-content/ ; mkdir -p \"${short_name}\""
scp ${kit_name}.zip ${server}:./${server}/protected-content/${short_name}/
scp ${kit_name}-debug.zip ${server}:./${server}/protected-content/${short_name}/
scp ${metadata} ${server}:./${server}/protected-content/${short_name}/
ssh ${server} "cd ./${server}/protected-content/ ; ln -sf \"${short_name}\"/\"${short_name}\"-\"${version}\".zip \"${short_name}\".zip"

rm -rf ${dst_path}
rm -rf ${debug_path}

