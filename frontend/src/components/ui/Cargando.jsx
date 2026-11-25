import './Cargando.css'

export default function Cargando({ texto = 'Cargando…' }) {
  return (
    <div className="cargando" role="status">
      <span className="cargando__circulo" aria-hidden="true" />
      <span>{texto}</span>
    </div>
  )
}
