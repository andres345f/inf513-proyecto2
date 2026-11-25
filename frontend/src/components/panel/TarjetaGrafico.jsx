import './Panel.css'

/** Contenedor de un gráfico con título y texto alternativo para lectores de pantalla. */
export default function TarjetaGrafico({ titulo, resumen, vacio = false, children }) {
  return (
    <section className="tarjeta-grafico">
      <h2 className="tarjeta-grafico__titulo">{titulo}</h2>
      {vacio ? <p className="tarjeta-grafico__vacio">No hay datos en este rango de fechas.</p> : (
        <>
          <div className="tarjeta-grafico__lienzo" aria-hidden="true">{children}</div>
          {resumen && <details><summary>Ver cifras de {titulo.toLocaleLowerCase('es')}</summary>
            <ul>{resumen.map((fila) => <li key={fila}>{fila}</li>)}</ul>
          </details>}
        </>
      )}
    </section>
  )
}
