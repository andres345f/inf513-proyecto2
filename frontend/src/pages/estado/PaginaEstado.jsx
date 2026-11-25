import { Link } from 'react-router-dom'
import './PaginaEstado.css'

/** Plantilla común de las páginas de estado (sección en construcción, sin permiso, no encontrada). */
export default function PaginaEstado({ icono: IconoEstado, titulo, children, enlace = { a: '/', texto: 'Volver al inicio' }, sinContador = false }) {
  // sinContador: la vista no se cuenta como visita en el pie (ver useContadorVisitas).
  return (
    <section className="pagina-estado" data-sin-contador={sinContador || undefined}>
      <IconoEstado className="pagina-estado__icono" size={48} aria-hidden="true" />
      <h1>{titulo}</h1>
      <div className="pagina-estado__texto">{children}</div>
      {enlace && <Link to={enlace.a} className="pagina-estado__enlace">{enlace.texto}</Link>}
    </section>
  )
}
