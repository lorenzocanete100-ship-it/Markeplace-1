"""Maintenance commands. Run in the same environment as the web service."""
import argparse
import getpass
import json
import os
import sqlite3
import tempfile
import time
import uuid
import zipfile
from pathlib import Path
from werkzeug.security import generate_password_hash


def main():
    parser = argparse.ArgumentParser(description='Administración de Plaza Cerca')
    parser.add_argument('command', choices=['create-admin','reset-password','backup'])
    parser.add_argument('--email')
    parser.add_argument('--output', default='backup-plaza-cerca.zip')
    args = parser.parse_args()
    root = Path(os.environ.get('DATA_DIR', './data'))
    database = root / 'market.db'
    if not database.exists():
        parser.error('Primero iniciá la aplicación para crear la base de datos.')
    with sqlite3.connect(database) as con:
        if args.command == 'backup':
            # Use SQLite's online backup API, never copy live WAL files.
            # Pause edits during backup so image files match this snapshot.
            with tempfile.TemporaryDirectory() as tmp:
                snap = Path(tmp) / 'market.db'
                with sqlite3.connect(snap) as dst:
                    con.backup(dst)
                    rows = dst.execute("SELECT photos FROM listings WHERE status!='deleted'").fetchall()
                with zipfile.ZipFile(args.output, 'w', zipfile.ZIP_DEFLATED) as archive:
                    archive.write(snap, 'market.db')
                    for row in rows:
                        for name in json.loads(row[0]):
                            archive.write(root / 'uploads' / name, 'uploads/' + name)
            print('Respaldo creado. Guardalo en un lugar privado: contiene datos de usuarios.')
            return
        email = (args.email or os.environ.get('ADMIN_EMAIL', '')).strip().lower()
        if '@' not in email:
            parser.error('Indicá --email o configurá ADMIN_EMAIL.')
        if args.command == 'create-admin' and email != os.environ.get('ADMIN_EMAIL', '').strip().lower():
            parser.error('El correo debe coincidir con ADMIN_EMAIL.')
        password = getpass.getpass('Nueva contraseña (mínimo 10 caracteres): ')
        if not 10 <= len(password) <= 128 or password != getpass.getpass('Repetí la contraseña: '):
            parser.error('Las contraseñas no coinciden o su longitud no es válida.')
        hashed = generate_password_hash(password, method='scrypt')
        if args.command == 'create-admin':
            if con.execute('SELECT 1 FROM users WHERE email=?',(email,)).fetchone():
                parser.error('La cuenta ya existe. Usá reset-password si corresponde.')
            con.execute('INSERT INTO users VALUES (?,?,?,?,?)',(uuid.uuid4().hex,email,'Administrador',hashed,int(time.time())))
        elif con.execute('UPDATE users SET password=? WHERE email=?',(hashed,email)).rowcount != 1:
            parser.error('No existe esa cuenta.')
        con.commit()
        print('Cuenta actualizada. No compartas la contraseña.')


if __name__ == '__main__':
    main()
