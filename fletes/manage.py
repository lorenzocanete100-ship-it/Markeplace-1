"""Comandos de mantenimiento. Correlos en el mismo entorno que el servicio web."""
import argparse
import getpass
import os
import sqlite3
import time
import uuid
from contextlib import closing
from pathlib import Path

from werkzeug.security import generate_password_hash


def main():
    parser = argparse.ArgumentParser(description='Administración de Fletá')
    parser.add_argument('command', choices=['create-admin', 'reset-password', 'backup'])
    parser.add_argument('--email')
    parser.add_argument('--output', default='backup-fleta.db')
    args = parser.parse_args()
    database = Path(os.environ.get('DATA_DIR', './data')) / 'mudanzas.db'
    if not database.exists():
        parser.error('Primero iniciá la aplicación para crear la base de datos.')
    with closing(sqlite3.connect(database)) as con:
        if args.command == 'backup':
            with closing(sqlite3.connect(args.output)) as dst:
                con.backup(dst)
            print('Respaldo creado (sin los documentos de choferes: copiá también DATA_DIR/docs). Guardalo en un lugar privado.')
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
            if con.execute('SELECT 1 FROM users WHERE email=?', (email,)).fetchone():
                parser.error('La cuenta ya existe. Usá reset-password.')
            phone = input('Celular del administrador: ').strip()
            con.execute('INSERT INTO users VALUES (?,?,?,?,?,?)', (uuid.uuid4().hex, email, 'Administrador', phone, hashed, int(time.time())))
        elif con.execute('UPDATE users SET password=? WHERE email=?', (hashed, email)).rowcount != 1:
            parser.error('No existe esa cuenta.')
        con.commit()
        print('Cuenta actualizada.')


if __name__ == '__main__':
    main()
