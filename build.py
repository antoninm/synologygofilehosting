#!/usr/bin/env python3
"""Build script for GoFileIo.host package."""
import tarfile
import json
import os

script_dir = os.path.dirname(os.path.abspath(__file__))

info_path = os.path.join(script_dir, 'INFO')
php_path  = os.path.join(script_dir, 'GoFileIo.php')

with open(info_path) as f:
    info = json.load(f)

version    = info['version']
host_name  = "GoFileIo({}).host".format(version)
host_path  = os.path.join(script_dir, host_name)

def add_file(tar, src, arcname):
    """Add a file with world-readable permissions (required by DS2 API C++ extractor)."""
    info = tarfile.TarInfo(name=arcname)
    data = open(src, 'rb').read()
    info.size  = len(data)
    info.mode  = 0o755   # world-readable — mode 0o600 causes ERR_INVALID_FILEHOST (1608)
    info.uid   = 1000
    info.gid   = 1000
    info.uname = 'user'
    info.gname = 'users'
    tar.addfile(info, __import__('io').BytesIO(data))

with tarfile.open(host_path, 'w:gz') as tar:
    # Flat archive — INFO and PHP at root, world-readable (mode 0o755)
    # Note: DS2 API C++ code extracts files and reads them as nobody/DownloadStation;
    # mode 0o600 would make extraction succeed but file_get_contents(INFO) fail → 1608.
    add_file(tar, info_path, 'INFO')
    add_file(tar, php_path,  'GoFileIo.php')

print(f"Created: {host_path}")

# Verify contents
with tarfile.open(host_path, 'r:gz') as tar:
    members = tar.getnames()
    print(f"Archive contents: {members}")
