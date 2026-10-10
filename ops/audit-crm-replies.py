import hashlib,json,pathlib,socket
root=pathlib.Path('/home/graphexpress/public_html')
files=['wp-content/mu-plugins/ge-crm/class-ge-crm.php','wp-content/mu-plugins/ge-crm/class-ge-crm-ui.php','wp-content/mu-plugins/ge-crm/class-ge-meta-social.php']
cfg_path=pathlib.Path('/home/graphexpress/whatsapp-crm/config.json')
cfg=json.loads(cfg_path.read_text()) if cfg_path.exists() else {}
out={'host':socket.gethostname(),'root_exists':root.is_dir(),'files':{f:hashlib.sha256((root/f).read_bytes()).hexdigest() for f in files},'private_config_keys':list(cfg),'outbound_configured':{k:bool(cfg.get(k)) for k in ['page_access_token','instagram_access_token','whatsapp_access_token','access_token','outbound']}}
print(json.dumps(out))
