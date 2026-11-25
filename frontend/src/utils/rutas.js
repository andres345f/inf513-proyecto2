import { entorno } from '../config/entorno'

/** Adapta una ruta pública absoluta al subdirectorio donde está publicada la aplicación. */
export function rutaPublica(ruta) {
  if (!ruta || /^(?:[a-z]+:)?\/\//i.test(ruta) || ruta.startsWith('data:') || ruta.startsWith('blob:')) return ruta

  const base = entorno.basename === '/' ? '' : entorno.basename.replace(/\/$/, '')
  return `${base}/${ruta.replace(/^\//, '')}`
}
