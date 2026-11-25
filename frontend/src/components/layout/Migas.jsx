import { Link } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'
import './Migas.css'

/**
 * Migas de navegación. items: [{ etiqueta, ruta? }] — la última no lleva ruta
 * y se marca como página actual. Omitir «ruta» también en las intermedias
 * que no sean enlaces (por ejemplo la bandeja de la que se vino).
 */
export default function Migas({ items = [], className = '' }) {
  if (items.length === 0) return null

  return (
    <nav className={`migas ${className}`.trim()} aria-label="Migas de navegación">
      <ol className="migas__lista">
        {items.map((item, indice) => {
          const ultimo = indice === items.length - 1
          return (
            <li key={item.etiqueta} className="migas__paso">
              {item.ruta && !ultimo ? (
                <Link to={item.ruta} className="migas__enlace">{item.etiqueta}</Link>
              ) : (
                <span className="migas__actual" aria-current={ultimo ? 'page' : undefined}>
                  {ultimo && <ChevronRight size={14} aria-hidden="true" />}
                  {item.etiqueta}
                </span>
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
