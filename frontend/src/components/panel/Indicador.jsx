import './Panel.css'

/** Cifra clave del panel. */
export default function Indicador({ etiqueta, valor, detalle, tono = 'normal' }) {
  return (
    <div className={`indicador indicador--${tono}`}>
      <span className="indicador__etiqueta">{etiqueta}</span>
      <strong className="indicador__valor">{valor}</strong>
      {detalle && <span className="indicador__detalle">{detalle}</span>}
    </div>
  )
}
