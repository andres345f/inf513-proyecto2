import './EncabezadoPagina.css'

/**
 * Encabezado estándar de página: título grande, descripción y la acción
 * principal alineada a la derecha. Reemplaza los <header><h1>… repetidos
 * en cada vista.
 *
 * - titulo / descripcion: identidad de la página.
 * - accion: nodo (normalmente un Boton) con la acción destacada.
 * - children: contenido opcional bajo la descripción (p. ej. pestañas).
 */
export default function EncabezadoPagina({ titulo, descripcion, accion = null, className = '', children }) {
  return (
    <header className={`pagina-encabezado ${className}`.trim()}>
      <div className="pagina-encabezado__textos">
        <h1 className="pagina-encabezado__titulo">{titulo}</h1>
        {descripcion && <p className="pagina-encabezado__descripcion">{descripcion}</p>}
        {children}
      </div>
      {accion && <div className="pagina-encabezado__accion">{accion}</div>}
    </header>
  )
}
